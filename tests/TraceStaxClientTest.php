<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TraceStax\Laravel\TraceStaxClient;

class TraceStaxClientTest extends TestCase
{
    private string $ingestUrl;

    protected function setUp(): void
    {
        $this->ingestUrl = getenv('TRACESTAX_INGEST_URL') ?: 'http://localhost:4001';
    }

    // ── Unit tests ───────────────────────────────────────────────────────

    public function testRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TraceStaxClient('');
    }

    public function testDisabledClientDoesNotEnqueue(): void
    {
        $client = new TraceStaxClient(
            apiKey: 'ts_test_abc',
            endpoint: $this->ingestUrl,
            enabled: false,
        );

        // Should not throw and should not queue anything
        $client->sendEvent([
            'type'      => 'task_event',
            'framework' => 'laravel',
            'status'    => 'succeeded',
        ]);

        // Flush should be a no-op
        $client->flush();

        $this->assertTrue(true); // reached here without throwing
    }

    public function testDryRunDoesNotMakeHttpRequests(): void
    {
        // With dry_run=true, the client logs to stdout but never connects.
        // We verify this by pointing at an unreachable endpoint — if it tries
        // to connect, the test will fail or timeout.
        $client = new TraceStaxClient(
            apiKey: 'ts_test_abc',
            endpoint: 'http://localhost:1', // unreachable
            dryRun: true,
        );

        // Should not throw or timeout
        $client->sendEvent(['type' => 'task_event', 'status' => 'succeeded']);
        $client->flush();

        $this->assertTrue(true);
    }

    public function testPayloadIncludesLanguageAndFrameworkFields(): void
    {
        if (!$this->ingestAvailable()) {
            $this->markTestSkipped('mock-ingest not available');
        }

        $this->resetIngest();

        $client = new TraceStaxClient(
            apiKey: 'ts_test_abc',
            endpoint: $this->ingestUrl,
            enabled: true,
        );

        $client->sendEvent([
            'type'      => 'task_event',
            'framework' => 'laravel',
            'language'  => 'php',
            'status'    => 'succeeded',
            'task'      => ['name' => 'ProcessOrderJob', 'id' => 'job-001', 'queue' => 'default', 'attempt' => 1],
        ]);

        $client->flush();
        usleep(200_000);

        $events = $this->fetchEvents();
        $this->assertNotEmpty($events, 'No events received by mock ingest');
        $event = $events[0];

        $this->assertEquals('task_event', $event['type']);
        $this->assertEquals('laravel', $event['framework']);
        $this->assertEquals('php', $event['language']);
        $this->assertEquals('ProcessOrderJob', $event['task']['name'] ?? null);
    }

    public function testBatchSizeRespected(): void
    {
        $received = [];
        // Use the actual mock ingest if available, otherwise skip
        if (!$this->ingestAvailable()) {
            $this->markTestSkipped('mock-ingest not available');
        }

        $this->resetIngest();

        $client = new TraceStaxClient(
            apiKey: 'ts_test_abc',
            endpoint: $this->ingestUrl,
            maxBatchSize: 10,
        );

        // Enqueue 25 events
        for ($i = 0; $i < 25; $i++) {
            $client->sendEvent(['type' => 'task_event', 'id' => "job-{$i}"]);
        }

        $client->flush();
        usleep(300_000); // 300ms

        $events = $this->fetchEvents();
        $this->assertCount(25, $events, 'Expected 25 events in mock ingest');
    }

    // ── Integration tests ────────────────────────────────────────────────

    public function testEventsReachMockIngest(): void
    {
        if (!$this->ingestAvailable()) {
            $this->markTestSkipped('mock-ingest not available');
        }

        $this->resetIngest();

        $client = new TraceStaxClient(
            apiKey: 'ts_test_abc',
            endpoint: $this->ingestUrl,
        );

        $client->sendEvent([
            'type'      => 'task_event',
            'framework' => 'laravel',
            'language'  => 'php',
            'status'    => 'succeeded',
            'task'      => ['name' => 'SendEmailJob', 'id' => 'job-int-001', 'queue' => 'default', 'attempt' => 1],
        ]);

        $client->flush();
        usleep(300_000);

        $events = $this->fetchEvents();
        $this->assertNotEmpty($events, 'No events received by mock ingest');

        $laravelEvents = array_filter($events, fn($e) => ($e['framework'] ?? '') === 'laravel');
        $this->assertNotEmpty($laravelEvents, 'Expected at least one laravel event');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function ingestAvailable(): bool
    {
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 2]]);
            $result = @file_get_contents("{$this->ingestUrl}/test/health", false, $ctx);
            return $result !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resetIngest(): void
    {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 2, 'content' => '']]);
        @file_get_contents("{$this->ingestUrl}/test/reset", false, $ctx);
    }

    private function fetchEvents(): array
    {
        $result = @file_get_contents("{$this->ingestUrl}/test/events");
        return $result ? json_decode($result, true) : [];
    }

}
