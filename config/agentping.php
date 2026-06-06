<?php

return [

    'api_key' => env('AGENTPING_API_KEY'),

    // Base URL is derived from the API key's region segment when left blank.
    // apk_eu_* -> https://eu.ingest.agentping.io; apk_us_* -> https://us.ingest.agentping.io.
    // Set AGENTPING_BASE_URL explicitly only when self-hosting or testing.
    'base_url' => env('AGENTPING_BASE_URL'),

    'queue_size' => (int) env('AGENTPING_QUEUE_SIZE', 1000),

    'flush_interval' => (float) env('AGENTPING_FLUSH_INTERVAL', 2.0),

    'batch_size' => (int) env('AGENTPING_BATCH_SIZE', 50),

    'request_timeout' => (float) env('AGENTPING_REQUEST_TIMEOUT', 2.0),

    'terminating_flush_timeout' => (float) env('AGENTPING_TERMINATING_FLUSH_TIMEOUT', 5.0),

    'listen_to_ai_sdk' => filter_var(env('AGENTPING_LISTEN_TO_AI_SDK', true), FILTER_VALIDATE_BOOLEAN),

    'auto_register_terminating' => filter_var(env('AGENTPING_AUTO_REGISTER_TERMINATING', true), FILTER_VALIDATE_BOOLEAN),

    'user_agent' => 'agentping-laravel/0.1.0',

];
