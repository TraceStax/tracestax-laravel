# tracestax/laravel-tracestax

Worker intelligence and observability for [Laravel Queue](https://laravel.com/docs/queues).

## Installation

```bash
composer require tracestax/laravel-tracestax
```

The package auto-registers its service provider via Laravel's package discovery.

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=tracestax-config
```

Add your API key to `.env`:

```dotenv
TRACESTAX_API_KEY=ts_live_your_key_here
```

### Available Environment Variables

| Variable | Default | Description |
|---|---|---|
| `TRACESTAX_API_KEY` | _(required)_ | Your TraceStax project API key |
| `TRACESTAX_ENDPOINT` | `https://ingest.tracestax.com` | Ingest API base URL |
| `TRACESTAX_ENABLED` | `true` | Set to `false` to disable instrumentation |
| `TRACESTAX_FLUSH_INTERVAL` | `5.0` | Seconds between automatic flushes |
| `TRACESTAX_MAX_BATCH_SIZE` | `100` | Max events per HTTP request |

## What's Monitored

- Job lifecycle (start, success, failure)
- Job duration and attempt count
- Worker heartbeat on queue loop
- Error fingerprinting with exception class, message, and stack trace
- Queue and connection metadata

## How It Works

The package subscribes to Laravel's built-in queue events:

- `JobProcessing` - records the start time
- `JobProcessed` - sends a succeeded event with duration
- `JobFailed` - sends a failed event with exception details
- `Looping` - sends a worker heartbeat

Events are buffered in memory and flushed in batches via `register_shutdown_function` to avoid adding latency to your jobs.

## License

MIT
