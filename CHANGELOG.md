# Changelog

All notable changes to the AgentPing Laravel SDK are documented here.
The format is based on
[Keep a Changelog](https://keepachangelog.com/), and this project
adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0] - 2026-09-17

### Fixed

- `input_tokens` on Anthropic calls is now the gross prompt size,
  including the cached prefix. Anthropic reports `input_tokens` net of
  the cache and `laravel/ai` passes that through as `promptTokens`;
  earlier releases forwarded it as-is. Ingest subtracts
  `cached_input_tokens` itself when it prices a call, so cached
  Anthropic calls were priced too low. OpenAI and Gemini already report
  gross and are unchanged.

### Added

- `AgentPing::guardCheck()`, a synchronous call to the control plane that
  checks your spend rules and the dashboard pause switch before a run
  starts. Hard mode throws `AgentPing\Laravel\Exceptions\Paused`; soft
  mode returns a `GuardVerdict`. Fails closed by default; pass
  `onUnreachable: 'allow'` to run when the gate cannot be reached. Uses a
  region-derived `control_url` (`AGENTPING_CONTROL_URL` to override).
- Full `laravel/ai` lifecycle coverage. Listeners for `StepCompleted`,
  `StepFailed`, `AgentFailed`, `ToolInvoked`, `ToolFailed`,
  `ProviderFailedOver` and `AgentFailedOver` join the existing
  `AgentPrompted`, `AgentStreamed` and `EmbeddingsGenerated` listeners,
  so a multi-step agent invocation becomes a run timeline of per-step
  `llm_call`, `tool_call`, failover `step` and `error` events.
- `AGENTPING_CAPTURE_TOOL_PAYLOADS` (default `true`) and
  `AGENTPING_TOOL_PAYLOAD_MAX_CHARS` (default `4000`) control whether
  tool arguments and results are sent as `tool_call` input and output.
- Streamed prompts carry `stream: true` on their `llm_call`.

### Changed

- When step events are seen for an invocation, `AgentPrompted` no longer
  emits its own aggregate `llm_call`, so tokens are priced once.
- A failed invocation now finishes its synthetic run with status `error`
  instead of leaving it open.
- Events from nested agent invocations attach to the outer run.

## [0.1.1] - 2026-06-06

### Fixed

- Named `Agent` classes now auto-name their runs. laravel/ai exposes the
  agent as an object on the prompt, which the resolver did not read, so
  every run fell back to the default agent name. The class basename is
  snake-cased; the anonymous `agent()` helper still uses the default.

### Added

- `AgentPing::useAgent($name)` to override the agent name for the
  current scope.

## [0.1.0] - 2026-05-17

Initial public release.

### Added

- Auto-registered service provider. `composer require agentping/laravel`
  is the only install step; config publishable via
  `php artisan vendor:publish --tag=agentping-config`.
- Run lifecycle through `AgentPing` facade. `AgentPing::run($name,
  fn ($run) => ...)`, `$run->event($type, $payload)`,
  `$run->finish($status, $scores)`.
- Client-generated UUIDv7 run IDs. `$run->id` is populated synchronously
  before any network call.
- `AgentPing::heartbeat($agent, status: 'ok', costUsd: ..., durationMs:
  ..., metadata: [...])` for cron-shaped jobs.
- Out-of-the-box listener for `Laravel\Ai\Events\AgentPrompted`. Toggle
  via `AGENTPING_LISTEN_TO_AI_SDK`.
- Bounded local queue (default 1000), drop-oldest on overflow, exposed
  via `AgentPing::status()`.
- Terminating middleware flush with a 5-second deadline. Toggle via
  `AGENTPING_AUTO_REGISTER_TERMINATING`.
- Region-aware default base URL. `apk_eu_*` keys route to
  `https://eu.ingest.agentping.io`; `apk_us_*` keys route to
  `https://us.ingest.agentping.io`. Override via `AGENTPING_BASE_URL`.

### Configuration

```php
// config/agentping.php
'api_key'                    => env('AGENTPING_API_KEY'),
'base_url'                   => env('AGENTPING_BASE_URL'),
'queue_size'                 => (int) env('AGENTPING_QUEUE_SIZE', 1000),
'flush_interval'             => (float) env('AGENTPING_FLUSH_INTERVAL', 2.0),
'batch_size'                 => (int) env('AGENTPING_BATCH_SIZE', 50),
'request_timeout'            => (float) env('AGENTPING_REQUEST_TIMEOUT', 2.0),
'terminating_flush_timeout'  => (float) env('AGENTPING_TERMINATING_FLUSH_TIMEOUT', 5.0),
'listen_to_ai_sdk'           => env('AGENTPING_LISTEN_TO_AI_SDK', true),
'auto_register_terminating'  => env('AGENTPING_AUTO_REGISTER_TERMINATING', true),
```

### Distribution

- Published as `agentping/laravel` on Packagist.
- Laravel 10, 11, 12, 13. PHP 8.2 or later.

## Notes on stability

The 0.x line is pre-1.0. Public API may change before 1.0.0. We do not
break the wire format between SDK and ingest without a version bump and
a migration note here.

[Unreleased]: https://github.com/agent-ping/agent-ping-laravel/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/agent-ping/agent-ping-laravel/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/agent-ping/agent-ping-laravel/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/agent-ping/agent-ping-laravel/releases/tag/v0.1.0
