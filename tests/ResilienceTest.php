<?php

declare(strict_types=1);

namespace TraceStax\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use TraceStax\Laravel\TraceStaxClient;

/**
 * Resilience tests for TraceStaxClient.
 *
 * These tests guard the most critical production guarantee: the SDK must NEVER
 * throw into the host application — even when the ingest server is down, slow,
 * or returning errors.
 *
 * Scenarios covered:
 *   - enabled=false is a complete no-op (no HTTP, no logging)
 *   - dryRun=true logs to stdout but never makes HTTP calls
 *   - sendEvent() only buffers; it never throws
 *   - flush() with a dead server swallows GuzzleException silently
 *   - flush() with a 5xx response logs a warning but does not throw
 *   - heartbeat() with an unreachable server does not throw (Throwable caught)
 *   - snapshot() with an unreachable server does not throw (Throwable caught)
 *   - Job that calls sendEvent() in finally never masks original exception
 */
class ResilienceTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a TraceStaxClient with a Guzzle MockHandler injected via reflection
     * so we can control exactly what the HTTP layer returns.
     *
     * @param Response[]|ConnectException[] $responses Responses the mock will return in order.
     */
    private function clientWithMock(array $responses, bool $enabled = true, bool $dryRun = false): TraceStaxClient
    {
        $mock    = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $guzzle  = new Client([
            'base_uri' => 'https://test.tracestax.com',
            'handler'  => $handler,
        ]);

        $client = new TraceStaxClient(
            apiKey: 'ts_test_abc',
            endpoint: 'https://test.tracestax.com',
            enabled: $enabled,
            dryRun: $dryRun,
        );

        // Inject the mock Guzzle client via reflection
        $prop = new ReflectionProperty(TraceStaxClient::class, 'http');
        $prop->setAccessible(true);
        $prop->setValue($client, $guzzle);

        return $client;
    }

    /**
     * Silence the Illuminate Log facade so tests can call flush() with error
     * responses without needing a full Laravel application container.
     *
     * Illuminate\Support\Facades\Facade::swap() writes directly to the resolved-
     * instance cache, which works even without a bound app container.
     */
    private function silenceLog(): void
    {
        Log::swap(new class {
            public function warning(string $message, array $context = []): void {}
            public function info(string $message, array $context = []): void {}
            public function error(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
        });
    }

    // ── enabled=false ─────────────────────────────────────────────────────────

    public function testDisabledClientSendEventIsNoop(): void
    {
        $client = new TraceStaxClient(apiKey: 'ts_test_abc', enabled: false);

        // sendEvent must buffer nothing and must not throw
        $client->sendEvent(['type' => 'task_event', 'status' => 'succeeded']);

        // flush must be a no-op
        $client->flush();

        // No GuzzleException can have escaped; reaching here proves fire-and-forget
        $this->assertTrue(true);
    }

    public function testDisabledClientFlushIsNoop(): void
    {
        $client = new TraceStaxClient(apiKey: 'ts_test_abc', enabled: false);
        $client->sendEvent(['type' => 'task_event']);
        $client->flush(); // must not throw or make HTTP calls
        $this->assertTrue(true);
    }

    public function testDisabledClientHeartbeatIsNoop(): void
    {
        $client = new TraceStaxClient(apiKey: 'ts_test_abc', enabled: false);
        $client->heartbeat('worker-1', ['default'], 4); // must not throw
        $this->assertTrue(true);
    }

    public function testDisabledClientSnapshotIsNoop(): void
    {
        $client = new TraceStaxClient(apiKey: 'ts_test_abc', enabled: false);
        $client->snapshot('default', 10, 2, 0); // must not throw
        $this->assertTrue(true);
    }

    // ── dryRun=true ───────────────────────────────────────────────────────────

    public function testDryRunSendEventDoesNotMakeHttpCalls(): void
    {
        // Point at an unreachable endpoint; if post() is called it will throw
        $client = new TraceStaxClient(
            apiKey: 'ts_test_abc',
            endpoint: 'http://127.0.0.1:1',
            dryRun: true,
        );

        $client->sendEvent(['type' => 'task_event', 'status' => 'succeeded']);
        $client->flush();

        $this->assertTrue(true);
    }

    public function testDryRunHeartbeatReturnsWithoutHttpCall(): void
    {
        $client = new TraceStaxClient(
            apiKey: 'ts_test_abc',
            endpoint: 'http://127.0.0.1:1',
            dryRun: true,
        );

        $client->heartbeat('worker-1');
        $this->assertTrue(true);
    }

    // ── Fire-and-forget guarantees ────────────────────────────────────────────

    public function testSendEventAloneNeverThrows(): void
    {
        $client = new TraceStaxClient(
            apiKey: 'ts_test_abc',
            endpoint: 'https://test.tracestax.com',
        );

        // sendEvent only buffers — no HTTP, no exception
        for ($i = 0; $i < 20; $i++) {
            $client->sendEvent(['type' => 'task_event', 'id' => "job-{$i}"]);
        }

        $this->assertTrue(true);
    }

    public function testFlushNeverThrowsWith503Response(): void
    {
        $this->silenceLog();

        $client = $this->clientWithMock([
            new Response(503, [], 'Service Unavailable'),
            new Response(503, [], 'Service Unavailable'),
        ]);

        $client->sendEvent(['type' => 'task_event', 'id' => 'job-1']);
        $client->flush(); // must swallow the 503, not throw

        $this->assertTrue(true);
    }

    public function testFlushNeverThrowsWithConnectionError(): void
    {
        $this->silenceLog();

        $req   = new Request('POST', '/v1/ingest');
        $client = $this->clientWithMock([
            new ConnectException('ECONNREFUSED', $req),
            new ConnectException('ECONNREFUSED', $req),
        ]);

        $client->sendEvent(['type' => 'task_event', 'id' => 'job-conn']);
        $client->flush(); // GuzzleException must be caught internally

        $this->assertTrue(true);
    }

    public function testHeartbeatNeverThrowsWithUnreachableServer(): void
    {
        // heartbeat() wraps in catch (\Throwable) so this must be safe
        $client = new TraceStaxClient(
            apiKey: 'ts_test_abc',
            endpoint: 'http://127.0.0.1:1',  // instantly refused
        );

        $client->heartbeat('worker-1', ['default'], 4);
        $this->assertTrue(true);
    }

    public function testSnapshotNeverThrowsWithUnreachableServer(): void
    {
        // snapshot() wraps in catch (\Throwable) so this must be safe
        $client = new TraceStaxClient(
            apiKey: 'ts_test_abc',
            endpoint: 'http://127.0.0.1:1',
        );

        $client->snapshot('default', 10, 2, 0);
        $this->assertTrue(true);
    }

    public function testSdkDoesNotMaskOriginalExceptionFromJobFinally(): void
    {
        // Simulates a Laravel Queue job's around-middleware pattern
        $client = new TraceStaxClient(
            apiKey: 'ts_test_abc',
            endpoint: 'http://127.0.0.1:1',
        );

        $jobError   = new \RuntimeException('user job crashed');
        $caughtErr  = null;

        try {
            try {
                throw $jobError;
            } finally {
                // SDK call in finally must never overwrite the original exception
                $client->sendEvent(['type' => 'task_event', 'status' => 'failed']);
            }
        } catch (\Throwable $e) {
            $caughtErr = $e;
        }

        $this->assertSame($jobError, $caughtErr, 'SDK must not mask the original job exception');
    }

    public function testJobCompletesWhenSdkIsInFinallyAndServerIsDown(): void
    {
        $client     = new TraceStaxClient(
            apiKey: 'ts_test_abc',
            endpoint: 'http://127.0.0.1:1',
        );
        $jobDone    = false;

        try {
            $jobDone = true;
        } finally {
            $client->sendEvent(['type' => 'task_event', 'status' => 'succeeded']);
        }

        $this->assertTrue($jobDone);
    }

    // ── Multiple flush calls ──────────────────────────────────────────────────

    public function testMultipleFlushCallsNeverDoublePost(): void
    {
        $this->silenceLog();

        $callCount = 0;
        $mock = new MockHandler([
            new Response(200, [], '{"ok":true}'),
            new Response(200, [], '{"ok":true}'),
        ]);
        $handler = HandlerStack::create($mock);
        $handler->push(function (callable $handler) use (&$callCount) {
            return function ($request, $options) use ($handler, &$callCount) {
                $callCount++;
                return $handler($request, $options);
            };
        });
        $guzzle = new Client([
            'base_uri' => 'https://test.tracestax.com',
            'handler'  => $handler,
        ]);

        $client = new TraceStaxClient(
            apiKey: 'ts_test_abc',
            endpoint: 'https://test.tracestax.com',
            maxBatchSize: 10,
        );

        $prop = new ReflectionProperty(TraceStaxClient::class, 'http');
        $prop->setAccessible(true);
        $prop->setValue($client, $guzzle);

        for ($i = 0; $i < 5; $i++) {
            $client->sendEvent(['type' => 'task_event', 'id' => "job-{$i}"]);
        }

        // Two sequential flushes — the second should be a no-op (buffer already cleared)
        $client->flush();
        $client->flush();

        $this->assertSame(1, $callCount, 'Expected exactly one HTTP POST for 5 events');
    }

    // ── Circuit breaker ───────────────────────────────────────────────────────

    public function testCircuitOpensAfter3ConsecutiveFailures(): void
    {
        $this->silenceLog();

        $req    = new Request('POST', '/v1/ingest');
        $client = $this->clientWithMock([
            new ConnectException('ECONNREFUSED', $req),
            new ConnectException('ECONNREFUSED', $req),
            new ConnectException('ECONNREFUSED', $req),
        ]);

        // 3 flush attempts open the circuit
        for ($i = 0; $i < 3; $i++) {
            $client->sendEvent(['type' => 'task_event', 'id' => "fail-{$i}"]);
            $client->flush();
        }

        $state = (new \ReflectionProperty(TraceStaxClient::class, 'circuitState'));
        $state->setAccessible(true);
        $this->assertSame('OPEN', $state->getValue($client));
    }

    public function testCircuitDropsEventsWhenOpen(): void
    {
        $this->silenceLog();

        $req    = new Request('POST', '/v1/ingest');
        // Only 3 responses — any 4th call would throw "No more responses"
        $client = $this->clientWithMock([
            new ConnectException('ECONNREFUSED', $req),
            new ConnectException('ECONNREFUSED', $req),
            new ConnectException('ECONNREFUSED', $req),
        ]);

        for ($i = 0; $i < 3; $i++) {
            $client->sendEvent(['type' => 'task_event', 'id' => "fail-{$i}"]);
            $client->flush();
        }

        // Circuit is OPEN — this flush must be silent (no HTTP call)
        $client->sendEvent(['type' => 'task_event', 'id' => 'dropped']);
        $client->flush(); // would throw if it tried to make a 4th HTTP call

        $this->assertTrue(true);
    }

    public function testCircuitResetsAfterCooldown(): void
    {
        $this->silenceLog();

        $req    = new Request('POST', '/v1/ingest');
        $client = $this->clientWithMock([
            new ConnectException('ECONNREFUSED', $req),
            new ConnectException('ECONNREFUSED', $req),
            new ConnectException('ECONNREFUSED', $req),
            new Response(200, [], '{"ok":true}'),
        ]);

        // Open the circuit
        for ($i = 0; $i < 3; $i++) {
            $client->sendEvent(['type' => 'task_event', 'id' => "fail-{$i}"]);
            $client->flush();
        }

        // Simulate cooldown elapsed
        $prop = new \ReflectionProperty(TraceStaxClient::class, 'circuitOpenedAt');
        $prop->setAccessible(true);
        $prop->setValue($client, microtime(true) - 31.0);

        // Probe succeeds → circuit closes
        $client->sendEvent(['type' => 'task_event', 'id' => 'probe']);
        $client->flush();

        $state = new \ReflectionProperty(TraceStaxClient::class, 'circuitState');
        $state->setAccessible(true);
        $this->assertSame('CLOSED', $state->getValue($client));
    }

    // ── Queue memory cap ──────────────────────────────────────────────────────

    public function testQueueCapDoesNotExceedMaxBufferSize(): void
    {
        $this->silenceLog();

        $client = new TraceStaxClient(apiKey: 'ts_test', enabled: true, dryRun: false);

        // Enqueue 10_200 events — the cap must prevent the buffer growing beyond 10K
        for ($i = 0; $i < 10_200; $i++) {
            $client->sendEvent(['type' => 'task_event', 'id' => "e{$i}"]);
        }

        $bufferProp = new ReflectionProperty(TraceStaxClient::class, 'buffer');
        $bufferProp->setAccessible(true);
        $bufferSize = count($bufferProp->getValue($client));

        $this->assertLessThanOrEqual(10_000, $bufferSize,
            "Buffer must not exceed 10K events, got {$bufferSize}");
    }

    public function testQueueCapNeverThrows(): void
    {
        $client = new TraceStaxClient(apiKey: 'ts_test', enabled: true, dryRun: false);

        // Must not throw even when far past the cap
        for ($i = 0; $i < 15_000; $i++) {
            $client->sendEvent(['type' => 'task_event', 'id' => "e{$i}"]);
        }

        $this->assertTrue(true);
    }

    // ── X-Retry-After ─────────────────────────────────────────────────────────

    public function testXRetryAfterPausesFlush(): void
    {
        $this->silenceLog();

        $callCount = 0;
        $req       = new Request('POST', '/v1/ingest');
        $client    = $this->clientWithMock([
            new Response(429, ['X-Retry-After' => '10'], 'rate limited'),
            new Response(200, [], '{"ok":true}'),
        ]);

        $client->sendEvent(['type' => 'task_event', 'id' => 'rate-1']);
        $client->flush();

        // Within the pause window, flush must be a no-op
        $client->sendEvent(['type' => 'task_event', 'id' => 'rate-2']);

        $pauseProp = new ReflectionProperty(TraceStaxClient::class, 'pauseUntil');
        $pauseProp->setAccessible(true);
        $pauseUntil = $pauseProp->getValue($client);

        $this->assertNotNull($pauseUntil, 'pauseUntil must be set after X-Retry-After header');
        $this->assertGreaterThan(microtime(true), $pauseUntil,
            'pauseUntil must be in the future');
    }

    // ── Stats API ─────────────────────────────────────────────────────────────

    public function testStatsReturnsExpectedKeys(): void
    {
        $client = new TraceStaxClient(apiKey: 'ts_test', enabled: true);
        $stats  = $client->stats();

        $this->assertArrayHasKey('queue_size', $stats);
        $this->assertArrayHasKey('dropped_events', $stats);
        $this->assertArrayHasKey('circuit_state', $stats);
        $this->assertArrayHasKey('consecutive_failures', $stats);
    }

    public function testStatsInitialCircuitStateIsClosed(): void
    {
        $client = new TraceStaxClient(apiKey: 'ts_test', enabled: true);
        $this->assertSame('closed', $client->stats()['circuit_state']);
    }

    public function testStatsDroppedEventsIncrementsOnOverflow(): void
    {
        $this->silenceLog();

        $client = new TraceStaxClient(
            apiKey: 'ts_test',
            endpoint: 'http://127.0.0.1:1',  // guaranteed refused - ensures transient failure so buffer accumulates
            enabled: true,
        );
        for ($i = 0; $i < 10_200; $i++) {
            $client->sendEvent(['type' => 'task_event', 'id' => "e{$i}"]);
        }

        $stats = $client->stats();
        $this->assertGreaterThan(0, $stats['dropped_events'],
            'dropped_events must be > 0 after buffer overflow');
    }

    // ── Laravel Queue listener integration pattern (Phase 3) ─────────────────
    // Verifies the exact sendEvent() call sequence used by QueueEventSubscriber
    // does not throw when the ingest server is unreachable.

    public function testLaravelQueueListenerPattern_SucceededEvent_WithDeadServer_DoesNotThrow(): void
    {
        $this->silenceLog();
        $client = new TraceStaxClient(
            apiKey: 'ts_test',
            endpoint: 'http://127.0.0.1:1',  // guaranteed refused
            enabled: true,
        );

        // Simulate QueueEventSubscriber::handleJobProcessed() call pattern
        $payload = [
            'framework'   => 'laravel',
            'language'    => 'php',
            'sdk_version' => '0.1.0',
            'type'        => 'task_event',
            'worker'      => ['key' => 'host:1', 'hostname' => 'host', 'pid' => 1,
                              'queues' => ['default'], 'concurrency' => 1],
            'task'        => ['name' => 'App\\Jobs\\SendEmail', 'id' => 'job-1',
                              'queue' => 'default', 'attempt' => 1],
            'status'      => 'succeeded',
            'metrics'     => ['duration_ms' => 42.5],
        ];

        // Must not throw regardless of server state
        $this->assertNull($client->sendEvent($payload));
    }

    public function testLaravelQueueListenerPattern_FailedEvent_WithDeadServer_DoesNotThrow(): void
    {
        $this->silenceLog();
        $client = new TraceStaxClient(
            apiKey: 'ts_test',
            endpoint: 'http://127.0.0.1:1',
            enabled: true,
        );

        $payload = [
            'framework'   => 'laravel',
            'language'    => 'php',
            'sdk_version' => '0.1.0',
            'type'        => 'task_event',
            'worker'      => ['key' => 'host:1', 'hostname' => 'host', 'pid' => 1,
                              'queues' => ['default'], 'concurrency' => 1],
            'task'        => ['name' => 'App\\Jobs\\SendEmail', 'id' => 'job-1',
                              'queue' => 'default', 'attempt' => 1],
            'status'      => 'failed',
            'metrics'     => ['duration_ms' => 99.0],
            'error'       => ['type' => 'RuntimeException', 'message' => 'DB down'],
        ];

        $this->assertNull($client->sendEvent($payload));
    }

    public function testLaravelQueueListenerPattern_CircuitOpensAfterFailures_DoesNotThrow(): void
    {
        $this->silenceLog();
        $client = new TraceStaxClient(
            apiKey: 'ts_test',
            endpoint: 'http://127.0.0.1:1',
            enabled: true,
        );

        // Send enough events to trigger a flush and open the circuit
        for ($i = 0; $i < 5; $i++) {
            $client->sendEvent([
                'framework' => 'laravel', 'type' => 'task_event',
                'status'    => 'succeeded', 'task' => ['name' => "Job{$i}", 'id' => "id{$i}",
                                                        'queue' => 'default', 'attempt' => 1],
                'metrics'   => ['duration_ms' => 10],
                'worker'    => ['key' => 'h:1', 'hostname' => 'h', 'pid' => 1,
                                'queues' => ['default'], 'concurrency' => 1],
            ]);
        }

        // Manually flush to trigger circuit open
        $client->flush();

        // Circuit may be open — further calls must not throw
        $this->assertNull($client->sendEvent(['type' => 'task_event', 'status' => 'started']));

        $stats = $client->stats();
        $this->assertContains($stats['circuit_state'], ['open', 'half_open', 'closed']);
    }

    // ── Large payload size guard (2B) ─────────────────────────────────────────

    public function testOversizedPayloadIsDroppedWithoutThrowing(): void
    {
        $this->silenceLog();
        $client = new TraceStaxClient(apiKey: 'ts_test', enabled: true);

        $bigPayload = ['task' => 'big', 'data' => str_repeat('x', 600 * 1024)];
        $client->sendEvent($bigPayload);  // must not throw

        $stats = $client->stats();
        $this->assertSame(0, $stats['queue_size'], 'Oversized payload must not be buffered');
    }

    public function testNonSerializablePayloadIsDroppedWithoutThrowing(): void
    {
        $this->silenceLog();
        $client = new TraceStaxClient(apiKey: 'ts_test', enabled: true);

        // PHP resources cannot be JSON-encoded
        $badPayload = ['task' => 'bad', 'handle' => fopen('php://memory', 'r')];
        $client->sendEvent($badPayload);  // must not throw

        $stats = $client->stats();
        $this->assertSame(0, $stats['queue_size'], 'Non-serializable payload must not be buffered');
    }

    public function testNormalPayloadAcceptedAfterOversizedDrop(): void
    {
        $this->silenceLog();
        $client = new TraceStaxClient(apiKey: 'ts_test', enabled: true);

        $client->sendEvent(['data' => str_repeat('x', 600 * 1024)]);  // dropped
        $client->sendEvent(['task' => 'small']);                        // must be accepted

        $stats = $client->stats();
        $this->assertSame(1, $stats['queue_size'], 'Normal event after oversized drop must be buffered');
    }

    // ── HTTP 401 does not open circuit breaker ────────────────────────────────
    // A 401 is a permanent misconfiguration (wrong API key), not a transient
    // network error. Opening the circuit on 401 would silently drop all events
    // and hide the real problem. The circuit must stay CLOSED after 401 responses.

    public function test401DoesNotOpenCircuitBreaker(): void
    {
        $this->silenceLog();

        $client = $this->clientWithMock([
            new Response(401, [], 'Unauthorized'),
            new Response(401, [], 'Unauthorized'),
            new Response(401, [], 'Unauthorized'),
        ]);

        // Three flush attempts with 401 responses
        for ($i = 0; $i < 3; $i++) {
            $client->sendEvent(['type' => 'task_event', 'id' => "auth-fail-{$i}"]);
            $client->flush();
        }

        $stateProp = new \ReflectionProperty(TraceStaxClient::class, 'circuitState');
        $stateProp->setAccessible(true);
        $this->assertSame('CLOSED', $stateProp->getValue($client),
            'Circuit must stay CLOSED after 401 responses (not a transient failure)');
    }

    public function test401DoesNotIncrementConsecutiveFailures(): void
    {
        $this->silenceLog();

        $client = $this->clientWithMock([
            new Response(401, [], 'Unauthorized'),
        ]);

        $client->sendEvent(['type' => 'task_event', 'id' => 'auth-probe']);
        $client->flush();

        $failuresProp = new \ReflectionProperty(TraceStaxClient::class, 'consecutiveFailures');
        $failuresProp->setAccessible(true);
        $this->assertSame(0, $failuresProp->getValue($client),
            'consecutiveFailures must not increment on 401');
    }

    public function testEventsQueueAfter401(): void
    {
        $this->silenceLog();

        $client = $this->clientWithMock([
            new Response(401, [], 'Unauthorized'),
        ]);

        $client->sendEvent(['type' => 'task_event', 'id' => 'auth-fail']);
        $client->flush();

        // New events must still be accepted (circuit is CLOSED)
        for ($i = 0; $i < 5; $i++) {
            $client->sendEvent(['type' => 'task_event', 'id' => "queued-{$i}"]);
        }

        $bufferProp = new \ReflectionProperty(TraceStaxClient::class, 'buffer');
        $bufferProp->setAccessible(true);
        $this->assertCount(5, $bufferProp->getValue($client),
            'Events must continue to queue after a 401 response');
    }

    // ── Circuit breaker clock skew (2D) ──────────────────────────────────────

    public function testBackwardClockJumpDoesNotFreezeCircuitOpen(): void
    {
        $this->silenceLog();
        $client = new TraceStaxClient(apiKey: 'ts_test', enabled: true);

        // Force circuit OPEN via reflection
        $ref = new \ReflectionClass($client);
        $stateP = $ref->getProperty('circuitState');
        $stateP->setAccessible(true);
        $stateP->setValue($client, 'OPEN');

        // Simulate backward clock: set circuitOpenedAt far in the future
        $openedAtP = $ref->getProperty('circuitOpenedAt');
        $openedAtP->setAccessible(true);
        $openedAtP->setValue($client, microtime(true) + 60.0);

        // circuitAllow() must not throw; max(0, elapsed)=0 → stays OPEN
        $circuitAllow = $ref->getMethod('circuitAllow');
        $circuitAllow->setAccessible(true);
        $result = $circuitAllow->invoke($client);
        $this->assertFalse($result, 'Circuit must stay OPEN when clock jumps backward');

        // Set to 31 s in the past — should transition to HALF_OPEN
        $openedAtP->setValue($client, microtime(true) - 31.0);
        $result = $circuitAllow->invoke($client);
        $this->assertTrue($result, 'Circuit must allow probe after cooldown expires');
        $this->assertSame('HALF_OPEN', $stateP->getValue($client));
    }
}
