<?php

declare(strict_types=1);

namespace TraceStax\Laravel\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Log;
use TraceStax\Laravel\TraceStaxClient;

/**
 * Subscribes to Laravel Queue lifecycle events and reports them to TraceStax.
 *
 * Events captured:
 *   - Queue::before  (JobProcessing)  -> record start time
 *   - Queue::after   (JobProcessed)   -> send succeeded event with duration
 *   - Queue::failing (JobFailed)      -> send failed event with exception info
 *   - Queue::looping (Looping)        -> heartbeat
 */
class QueueEventSubscriber
{
    private const SDK_VERSION = '0.1.0';

    /**
     * Map of job IDs to their start timestamps (monotonic, in microseconds).
     *
     * @var array<string, float>
     */
    private array $startTimes = [];

    public function __construct(
        private readonly TraceStaxClient $client,
    ) {}

    /**
     * Register listeners on the event dispatcher.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, [$this, 'handleJobProcessing']);
        $events->listen(JobProcessed::class, [$this, 'handleJobProcessed']);
        $events->listen(JobFailed::class, [$this, 'handleJobFailed']);
        $events->listen(Looping::class, [$this, 'handleLooping']);
    }

    /**
     * Record the start time when a job begins processing.
     */
    public function handleJobProcessing(JobProcessing $event): void
    {
        $jobId = $this->getJobId($event->job);
        $this->startTimes[$jobId] = hrtime(true);
    }

    /**
     * Send a succeeded event when a job completes successfully.
     */
    public function handleJobProcessed(JobProcessed $event): void
    {
        $jobId = $this->getJobId($event->job);
        $durationMs = $this->calculateDuration($jobId);

        $this->client->sendEvent([
            'framework' => 'laravel',
            'language' => 'php',
            'sdk_version' => self::SDK_VERSION,
            'type' => 'task_event',
            'worker' => $this->buildWorkerPayload($event->job),
            'task' => $this->buildTaskPayload($event->job),
            'status' => 'succeeded',
            'metrics' => [
                'duration_ms' => $durationMs,
            ],
        ]);

        unset($this->startTimes[$jobId]);
    }

    /**
     * Send a failed event when a job fails, including exception details.
     */
    public function handleJobFailed(JobFailed $event): void
    {
        $jobId = $this->getJobId($event->job);
        $durationMs = $this->calculateDuration($jobId);

        $payload = [
            'framework' => 'laravel',
            'language' => 'php',
            'sdk_version' => self::SDK_VERSION,
            'type' => 'task_event',
            'worker' => $this->buildWorkerPayload($event->job),
            'task' => $this->buildTaskPayload($event->job),
            'status' => 'failed',
            'metrics' => [
                'duration_ms' => $durationMs,
            ],
        ];

        if ($event->exception !== null) {
            $payload['error'] = [
                'type' => get_class($event->exception),
                'message' => $event->exception->getMessage(),
                'stack_trace' => $this->formatStackTrace($event->exception),
            ];
        }

        $this->client->sendEvent($payload);

        unset($this->startTimes[$jobId]);
    }

    /**
     * Send a heartbeat when the queue worker loops.
     */
    public function handleLooping(Looping $event): void
    {
        $this->client->sendHeartbeat([
            'framework' => 'laravel',
            'language' => 'php',
            'sdk_version' => self::SDK_VERSION,
            'type' => 'heartbeat',
            'worker' => [
                'key' => gethostname() . ':' . getmypid(),
                'hostname' => gethostname(),
                'pid' => getmypid(),
            ],
            'queue' => $event->queue ?? 'default',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Build the worker section of the event payload.
     *
     * @param \Illuminate\Contracts\Queue\Job $job
     * @return array<string, mixed>
     */
    private function buildWorkerPayload($job): array
    {
        return [
            'key' => gethostname() . ':' . getmypid(),
            'hostname' => gethostname(),
            'pid' => getmypid(),
            'queues' => [$job->getQueue() ?? 'default'],
            'concurrency' => 1,
        ];
    }

    /**
     * Build the task section of the event payload.
     *
     * @param \Illuminate\Contracts\Queue\Job $job
     * @return array<string, mixed>
     */
    private function buildTaskPayload($job): array
    {
        return [
            'name' => $job->resolveName(),
            'id' => $this->getJobId($job),
            'queue' => $job->getQueue() ?? 'default',
            'attempt' => $job->attempts(),
        ];
    }

    /**
     * Calculate the duration in milliseconds from a recorded start time.
     */
    private function calculateDuration(string $jobId): ?float
    {
        if (! isset($this->startTimes[$jobId])) {
            return null;
        }

        $elapsedNs = hrtime(true) - $this->startTimes[$jobId];

        return round($elapsedNs / 1_000_000, 2);
    }

    /**
     * Extract a stable job identifier.
     *
     * @param \Illuminate\Contracts\Queue\Job $job
     */
    private function getJobId($job): string
    {
        return (string) ($job->getJobId() ?? $job->uuid() ?? spl_object_id($job));
    }

    /**
     * Format an exception's stack trace, limited to the first 20 frames.
     */
    private function formatStackTrace(\Throwable $exception): string
    {
        $trace = explode("\n", $exception->getTraceAsString());

        return implode("\n", array_slice($trace, 0, 20));
    }
}
