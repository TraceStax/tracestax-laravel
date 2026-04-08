<?php

declare(strict_types=1);

namespace TraceStax\Laravel;

use Illuminate\Support\ServiceProvider;
use TraceStax\Laravel\Listeners\QueueEventSubscriber;

class TraceStaxServiceProvider extends ServiceProvider
{
    /**
     * Register bindings in the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/tracestax.php', 'tracestax');

        $this->app->singleton(TraceStaxClient::class, function ($app): TraceStaxClient {
            /** @var array{api_key: string|null, endpoint: string, max_batch_size: int} $config */
            $config = $app['config']['tracestax'];

            return new TraceStaxClient(
                apiKey: $config['api_key'] ?? '',
                endpoint: $config['endpoint'],
                maxBatchSize: (int) $config['max_batch_size'],
            );
        });
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/tracestax.php' => config_path('tracestax.php'),
        ], 'tracestax-config');

        if (! $this->app['config']['tracestax.enabled']) {
            return;
        }

        if (empty($this->app['config']['tracestax.api_key'])) {
            return;
        }

        // Subscribe to queue events
        $this->app->make(QueueEventSubscriber::class)->subscribe(
            $this->app['events']
        );
    }
}
