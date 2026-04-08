<?php

declare(strict_types=1);

namespace TraceStax\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client that buffers events and flushes them to the TraceStax ingest API.
 *
 * Events are accumulated in memory during the request/job lifecycle and sent
 * in a single batch via register_shutdown_function to avoid adding latency
 * to the critical path.
 */
class TraceStaxClient
{
    private const INGEST_PATH = '/v1/ingest';
    private const HEARTBEAT_PATH = '/v1/heartbeat';
    private const SNAPSHOT_PATH = '/v1/snapshot';
    private const USER_AGENT = 'tracestax-laravel/0.1.0';

    private const CIRCUIT_THRESHOLD = 3;
    private const CIRCUIT_COOLDOWN_S = 30;
    private const MAX_BUFFER_SIZE = 10_000;
    private const TRIM_BUFFER_TO = 5_000;

    private Client $http;

    /** @var array<int, array<string, mixed>> */
    private array $buffer = [];

    private bool $shutdownRegistered = false;

    private bool $enabled;
    private bool $dryRun;

    // Circuit breaker state
    private int $consecutiveFailures = 0;
    private string $circuitState = 'CLOSED'; // 'CLOSED' | 'OPEN' | 'HALF_OPEN'
    private ?float $circuitOpenedAt = null;
    private ?float $pauseUntil = null;
    private int $droppedEvents = 0;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint = 'https://ingest.tracestax.com',
        private readonly int $maxBatchSize = 100,
        ?bool $enabled = null,
        ?bool $dryRun = null,
    ) {
        if (empty($this->apiKey)) {
            throw new \InvalidArgumentException('TraceStax API key is required');
        }

        $this->enabled = $enabled ?? (getenv('TRACESTAX_ENABLED') !== 'false');
        $this->dryRun = $dryRun ?? (getenv('TRACESTAX_DRY_RUN') === 'true');

        $this->http = new Client([
            'base_uri'        => rtrim($this->endpoint, '/'),
            'timeout'         => 10,
            'connect_timeout' => 5,
            'http_errors'     => false, // return 4xx/5xx as Response objects, never throw
            'headers'         => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
                'User-Agent'    => self::USER_AGENT,
            ],
        ]);
    }

    /**
     * Queue an event for batched delivery.
     *
     * Events are held in memory and flushed either when the buffer reaches
     * max_batch_size or at shutdown.
     *
     * @param array<string, mixed> $payload
     */
    public function sendEvent(array $payload): void
    {
        if (!$this->enabled) {
            return;
        }

        // Guard against huge or non-serializable payloads that would fail or
        // OOM at flush time and poison a whole batch of otherwise-valid events.
        $encoded = json_encode($payload);
        if ($encoded === false) {
            Log::warning('[tracestax] sendEvent: payload not serializable, dropping');
            return;
        }
        if (strlen($encoded) > 512 * 1024) {
            Log::warning('[tracestax] sendEvent: payload exceeds 512 KB, dropping');
            return;
        }

        if ($this->dryRun) {
            echo '[tracestax dry-run] ' . $encoded . PHP_EOL;
            return;
        }

        if (!isset($payload['language'])) {
            $payload['language'] = 'php';
        }
        if (!isset($payload['sdk_version'])) {
            $payload['sdk_version'] = '0.1.0';
        }

        $this->buffer[] = $payload;

        // Queue depth guard — prevent unbounded memory growth when server is slow/down
        if (count($this->buffer) > self::MAX_BUFFER_SIZE) {
            $excess = count($this->buffer) - self::TRIM_BUFFER_TO;
            array_splice($this->buffer, 0, $excess);
            $this->droppedEvents += $excess;
            Log::warning('[tracestax] Event buffer full, trimmed ' . $excess . ' oldest events');
        }

        $this->ensureShutdownHandler();

        if (count($this->buffer) >= $this->maxBatchSize) {
            $this->flush();
        }
    }

    /**
     * Flush all buffered events to the TraceStax ingest API.
     *
     * Unlike a naive implementation, we do NOT clear the buffer before
     * confirming delivery. If a batch POST fails, we restore it and all
     * subsequent batches to the front of the buffer so events are not
     * permanently lost (consistent with the Node.js and Python SDKs which
     * also re-queue on failure).
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $batches = array_chunk($this->buffer, $this->maxBatchSize);
        $this->buffer = [];

        foreach ($batches as $index => $batch) {
            $result = $this->postReturningSuccess(self::INGEST_PATH, ['events' => $batch]);
            if ($result === false) {
                // Transient failure — restore the failed batch plus any batches not yet
                // attempted, prepending them to whatever new events arrived during this flush.
                $unsent = array_merge(...array_slice($batches, $index));
                $this->buffer = array_merge($unsent, $this->buffer);
                break;
            } elseif ($result === null) {
                // Permanent failure (e.g. 401) — discard the batch, don't re-queue.
                break;
            }
        }
    }

    /**
     * Send a heartbeat payload immediately.
     *
     * @param array<string, mixed> $payload
     */
    public function sendHeartbeat(array $payload): void
    {
        if (!$this->enabled) {
            return;
        }
        if ($this->dryRun) {
            $encoded = json_encode($payload);
            echo '[tracestax dry-run] heartbeat ' . ($encoded !== false ? $encoded : '[payload not serializable]') . PHP_EOL;
            return;
        }
        if (!isset($payload['language'])) {
            $payload['language'] = 'php';
        }
        if (!isset($payload['sdk_version'])) {
            $payload['sdk_version'] = '0.1.0';
        }
        $this->post(self::HEARTBEAT_PATH, $payload);
    }

    /**
     * Send a typed worker heartbeat.
     *
     * The call is fire-and-forget: all exceptions are swallowed so that
     * monitoring can never disrupt the Laravel worker process.
     *
     * @param string   $workerKey   Unique worker identifier, typically "hostname:pid".
     * @param string[] $queues      Queue names this worker is consuming.
     * @param int      $concurrency Number of concurrent worker slots.
     */
    public function heartbeat(string $workerKey, array $queues = [], int $concurrency = 1): void
    {
        if (!$this->enabled) {
            return;
        }
        try {
            $payload = [
                'framework'   => 'laravel',
                'language'    => 'php',
                'sdk_version' => '0.1.0',
                'timestamp'   => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                'worker'      => [
                    'key'         => $workerKey,
                    'hostname'    => gethostname() ?: 'unknown',
                    'pid'         => getmypid() ?: 0,
                    'queues'      => $queues,
                    'concurrency' => $concurrency,
                ],
            ];

            $this->post(self::HEARTBEAT_PATH, $payload);
        } catch (\Throwable) {
            // Swallow — monitoring must never break the application.
        }
    }

    /**
     * Send a queue-depth snapshot for a single queue.
     *
     * The call is fire-and-forget: all exceptions are swallowed so that
     * monitoring can never disrupt the Laravel worker process.
     *
     * @param string   $queueName   Name of the queue being reported.
     * @param int      $depth       Number of messages waiting in the queue.
     * @param int|null $activeCount Number of messages currently being processed.
     * @param int|null $failedCount Number of messages in the failed queue.
     */
    public function snapshot(string $queueName, int $depth, ?int $activeCount = null, ?int $failedCount = null): void
    {
        if (!$this->enabled) {
            return;
        }
        try {
            $payload = [
                'framework'    => 'laravel',
                'worker_key'   => gethostname() . ':' . getmypid(),
                'queues'       => [[
                    'name'              => $queueName,
                    'depth'             => $depth,
                    'active'            => $activeCount ?? 0,
                    'failed'            => $failedCount ?? 0,
                    'throughput_per_min' => 0,
                ]],
                'timestamp'    => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            ];

            $this->post(self::SNAPSHOT_PATH, $payload);
        } catch (\Throwable) {
            // Swallow — monitoring must never break the application.
        }
    }

    /**
     * Register a shutdown function so buffered events are always delivered,
     * even if the process exits unexpectedly.
     */
    private function ensureShutdownHandler(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;

        register_shutdown_function(function (): void {
            try {
                $this->flush();
            } catch (\Throwable) {
                // Swallow — monitoring should never break the application.
            }
        });
    }

    /**
     * POST body to path. Returns true on HTTP 2xx, false on any failure.
     * Does NOT swallow errors — callers decide how to handle failure.
     *
     * @param array<string, mixed> $body
     */
    /**
     * Returns true on success, false to re-queue the batch (transient failure),
     * or null to discard the batch without re-queuing (permanent failure, e.g. 401).
     */
    private function postReturningSuccess(string $path, array $body): ?bool
    {
        if (!$this->enabled) {
            return true; // disabled = treat as success so buffer is cleared
        }
        if ($this->dryRun) {
            $encoded = json_encode($body);
            echo '[tracestax dry-run] ' . $path . ' ' . ($encoded !== false ? $encoded : '[payload not serializable]') . PHP_EOL;
            return true;
        }
        if (!$this->circuitAllow()) {
            return false;
        }
        if ($this->pauseUntil !== null && microtime(true) < $this->pauseUntil) {
            return false;
        }
        try {
            $response = $this->http->post($path, ['json' => $body, 'http_errors' => false]);

            $retryAfter = $response->getHeaderLine('X-Retry-After');
            if ($retryAfter !== '') {
                $secs = (int) $retryAfter;
                if ($secs > 0) {
                    $this->pauseUntil = microtime(true) + $secs;
                }
            }

            if ($response->getStatusCode() === 401) {
                // Auth failures are NOT counted as circuit-breaker failures — the
                // circuit would open and silently drop all events, masking the real problem.
                // Return null (not false) so flush() discards the batch rather than re-queuing;
                // 401 is a permanent misconfiguration, not a transient error worth retrying.
                Log::error('[tracestax] Auth failed (401) – check your API key. Events will continue to queue.');
                return null;
            }
            if ($response->getStatusCode() >= 400) {
                $this->recordFailure();
                Log::warning('TraceStax ingest responded with status ' . $response->getStatusCode());
                return false;
            }

            $this->recordSuccess();
            return true;
        } catch (GuzzleException $e) {
            $this->recordFailure();
            Log::warning('Failed to send events to TraceStax ingest', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body): void
    {
        if (!$this->enabled) {
            return;
        }
        if ($this->dryRun) {
            $encoded = json_encode($body);
            echo '[tracestax dry-run] ' . $path . ' ' . ($encoded !== false ? $encoded : '[payload not serializable]') . PHP_EOL;
            return;
        }
        if (!$this->circuitAllow()) {
            return;
        }
        // Honour backpressure pause
        if ($this->pauseUntil !== null && microtime(true) < $this->pauseUntil) {
            return;
        }
        try {
            $response = $this->http->post($path, [
                'json'        => $body,
                'http_errors' => false, // return 4xx/5xx as Response objects, never throw
            ]);

            // Honor X-Retry-After backpressure header
            $retryAfter = $response->getHeaderLine('X-Retry-After');
            if ($retryAfter !== '') {
                $secs = (int) $retryAfter;
                if ($secs > 0) {
                    $this->pauseUntil = microtime(true) + $secs;
                }
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode === 401) {
                // Auth failures are NOT counted as circuit-breaker failures — the
                // circuit would open and silently drop all events, masking the real problem.
                Log::error('[tracestax] Auth failed (401) – check your API key. Events will continue to queue.');
            } elseif ($statusCode >= 400) {
                $this->recordFailure();
                Log::warning('TraceStax ingest responded with status ' . $statusCode, [
                    'body' => substr((string) $response->getBody(), 0, 200),
                ]);
            } else {
                $this->recordSuccess();
            }
        } catch (GuzzleException $e) {
            $this->recordFailure();
            Log::warning('Failed to send events to TraceStax ingest', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Returns a snapshot of the client's internal health metrics.
     *
     * @return array{queue_size: int, dropped_events: int, circuit_state: string, consecutive_failures: int}
     */
    public function stats(): array
    {
        return [
            'queue_size'           => count($this->buffer),
            'dropped_events'       => $this->droppedEvents,
            'circuit_state'        => strtolower($this->circuitState),
            'consecutive_failures' => $this->consecutiveFailures,
        ];
    }

    private function circuitAllow(): bool
    {
        if ($this->circuitState === 'OPEN') {
            $elapsed = max(0, microtime(true) - ($this->circuitOpenedAt ?? 0));
            if ($elapsed < self::CIRCUIT_COOLDOWN_S) {
                return false;
            }
            $this->circuitState = 'HALF_OPEN';
        }
        return true;
    }

    private function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->circuitState = 'CLOSED';
        $this->circuitOpenedAt = null;
        // Do NOT reset pauseUntil — let any active X-Retry-After window expire naturally.
        // Clearing it here would cancel a backpressure pause set by a concurrent request path.
    }

    private function recordFailure(): void
    {
        $this->consecutiveFailures++;
        if ($this->consecutiveFailures >= self::CIRCUIT_THRESHOLD && $this->circuitState === 'CLOSED') {
            $this->circuitState = 'OPEN';
            $this->circuitOpenedAt = microtime(true);
            Log::warning('TraceStax unreachable, circuit open, events dropped');
        } elseif ($this->circuitState === 'HALF_OPEN') {
            $this->circuitState = 'OPEN';
            $this->circuitOpenedAt = microtime(true);
        }
    }
}
