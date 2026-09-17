<?php

return [

    'api_key' => env('AGENTPING_API_KEY'),

    // Base URL is derived from the API key's region segment when left blank.
    // apk_eu_* -> https://eu.ingest.agentping.io; apk_us_* -> https://us.ingest.agentping.io.
    // Set AGENTPING_BASE_URL explicitly only when self-hosting or testing.
    'base_url' => env('AGENTPING_BASE_URL'),

    // The guard gate (guard-checks-spec) is served by the control plane, not
    // the ingest edge, so it has its own base URL. Derived from the key's
    // region when left blank: apk_eu_* -> https://agentping.io (apex);
    // other regions -> https://{region}.agentping.io.
    'control_url' => env('AGENTPING_CONTROL_URL'),

    'queue_size' => (int) env('AGENTPING_QUEUE_SIZE', 1000),

    'flush_interval' => (float) env('AGENTPING_FLUSH_INTERVAL', 2.0),

    'batch_size' => (int) env('AGENTPING_BATCH_SIZE', 50),

    'request_timeout' => (float) env('AGENTPING_REQUEST_TIMEOUT', 2.0),

    // Allows a couple of backoff retries when an event/finish arrives before
    // its run-start has been persisted (eventual consistency on the ingest
    // side), including a cold-started worker.
    'terminating_flush_timeout' => (float) env('AGENTPING_TERMINATING_FLUSH_TIMEOUT', 10.0),

    'listen_to_ai_sdk' => filter_var(env('AGENTPING_LISTEN_TO_AI_SDK', true), FILTER_VALIDATE_BOOLEAN),

    'auto_register_terminating' => filter_var(env('AGENTPING_AUTO_REGISTER_TERMINATING', true), FILTER_VALIDATE_BOOLEAN),

    // Agent name for auto-instrumented laravel/ai calls that can't be attributed
    // to a named Agent class (e.g. the anonymous agent() helper) and aren't
    // wrapped in a run. Without this they would each land under a throwaway name.
    // Override per call/scope with AgentPing::run('name') or
    // AgentPing::agent('name', fn () => ...).
    'default_agent' => env('AGENTPING_DEFAULT_AGENT', 'ai-agent'),

    // Send tool arguments and results on tool_call events from laravel/ai.
    // Prompt and completion text are never sent. Turn this off if tool
    // payloads can carry customer data you do not want in AgentPing.
    'capture_tool_payloads' => filter_var(env('AGENTPING_CAPTURE_TOOL_PAYLOADS', true), FILTER_VALIDATE_BOOLEAN),

    'tool_payload_max_chars' => (int) env('AGENTPING_TOOL_PAYLOAD_MAX_CHARS', 4000),

    'user_agent' => 'agentping-laravel/0.1.0',

];
