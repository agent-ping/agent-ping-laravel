# agentping/laravel

Production observability for AI agents. Laravel SDK for
[AgentPing](https://agentping.io): cost attribution (Spend), live
monitoring (Pulse), quality scoring (Verify).
[docs](https://agentping.io/docs/sdks/laravel).

The SDK is designed to be invisible. It never blocks the request
lifecycle, never crashes user code, and ships telemetry on a 2-second
batch cycle to the regional ingest host that matches your API key.

If your app uses [`laravel/ai`](https://github.com/laravel/ai), every
prompt, stream, and embedding is reported to AgentPing automatically.
No user code changes.

## Install

```bash
composer require agentping/laravel
```

Set your API key:

```env
AGENTPING_API_KEY=apk_eu_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

The base URL is derived from the key's region segment: `apk_eu_*` routes
to `https://eu.ingest.agentping.io`, `apk_us_*` routes to
`https://us.ingest.agentping.io`. Override only when self-hosting or
testing.

Optional:

```env
# AGENTPING_BASE_URL=https://eu.ingest.agentping.io  # auto-derived; only set to override
AGENTPING_QUEUE_SIZE=1000
AGENTPING_BATCH_SIZE=50
AGENTPING_LISTEN_TO_AI_SDK=true
```

Publish the config if you want to override defaults:

```bash
php artisan vendor:publish --tag=agentping-config
```

## Quickstart

```php
use AgentPing\Laravel\Facades\AgentPing;

$run = AgentPing::run('support-triage',
    customerId: 'acme',
    feature: 'ticket-routing',
    metadata: ['ticket_id' => 'T-12345'],
);

$run->event('log', ['message' => 'classifying intent']);

$run->event('llm_call', [
    'provider' => 'anthropic',
    'model' => 'claude-opus-4-5',
    'input_tokens' => 1200,
    'output_tokens' => 480,
    'latency_ms' => 1830,
]);

$run->finish(status: 'success', scores: ['confidence' => 0.92]);
```

`$run->id()` is set before the constructor returns, with no network call.

### Heartbeats

For cron jobs, queues, or anything that runs to completion as a single unit:

```php
AgentPing::heartbeat('daily-summary',
    status: 'ok',
    costUsd: 0.084,
    durationMs: 12300,
    metadata: ['items' => 47],
);
```

### Inspect the queue

```php
AgentPing::status();
// ['queue_size' => 0, 'dropped_count' => 0, 'last_flush_at' => Carbon, 'last_error' => null]
```

### Manual flush

```php
AgentPing::flush(timeoutSeconds: 5.0);
```

Also exposed as a console command for long-running workers:

```bash
php artisan agentping:flush
```

## Laravel AI integration

If you install `laravel/ai`, the SDK auto-registers listeners for `AgentPrompted`, `AgentStreamed`, and `EmbeddingsGenerated`. Every prompt produces an `llm_call` event on the current AgentPing run; if there is no open run, a synthetic run keyed by the invocation id is created and auto-finished.

Mapped fields:

- `data.provider`, `data.model` from the response meta.
- `data.input_tokens`, `data.output_tokens` from the usage value object.
- `data.cached_input_tokens`, `data.cache_creation_input_tokens`, `data.reasoning_tokens` are emitted only when non-zero.
- `data.latency_ms` measured from `PromptingAgent` to `AgentPrompted`.
- `cost_usd` is never sent; the AgentPing server computes it from the rate card.

To disable auto-instrumentation:

```env
AGENTPING_LISTEN_TO_AI_SDK=false
```

## Contract guarantees

1. Run IDs are generated client-side as UUIDv7 with prefix `run_<region>_<32 hex>`.
2. Telemetry never blocks user code. Queue is in-memory, flushed on `app()->terminating()` and on `php artisan agentping:flush`.
3. 2-second hard timeout on every HTTP request.
4. Bounded queue, default 1000, drop-oldest semantics with a `dropped_count` counter.
5. Batched flush, up to 50 events per POST.
6. Hard ceiling of roughly 1 req/sec to the API.
7. One `Log::warning` per error class (auth, network, server).
8. On terminating, flush is bounded to 5 seconds.

## Long-running workers

Laravel queue workers and Horizon processes can stay up for hours. Schedule a recurring flush in `app/Console/Kernel.php`:

```php
$schedule->command('agentping:flush')->everyMinute();
```

Or call `AgentPing::flush()` between jobs.

## License

MIT.
