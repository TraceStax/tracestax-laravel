<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TraceStax API Key
    |--------------------------------------------------------------------------
    |
    | Your TraceStax project API key. You can find this in the TraceStax dashboard
    | under Settings > API Keys. Keys are prefixed with ts_live_ or ts_test_.
    |
    */

    'api_key' => env('TRACESTAX_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Ingest Endpoint
    |--------------------------------------------------------------------------
    |
    | The base URL of the TraceStax ingest API. You should not need to change
    | this unless you are using a self-hosted or regional deployment.
    |
    */

    'endpoint' => env('TRACESTAX_ENDPOINT', 'https://ingest.tracestax.com'),

    /*
    |--------------------------------------------------------------------------
    | Flush Interval
    |--------------------------------------------------------------------------
    |
    | How often (in seconds) the background buffer flushes queued events to
    | the ingest API. Lower values reduce delivery latency at the cost of
    | more HTTP requests.
    |
    */

    'flush_interval' => env('TRACESTAX_FLUSH_INTERVAL', 5.0),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Set to false to completely disable TraceStax instrumentation. Useful for
    | local development or environments where you don't want telemetry.
    |
    */

    'enabled' => env('TRACESTAX_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Max Batch Size
    |--------------------------------------------------------------------------
    |
    | Maximum number of events sent per HTTP request. Larger batches are more
    | efficient but use more memory.
    |
    */

    'max_batch_size' => env('TRACESTAX_MAX_BATCH_SIZE', 100),

];
