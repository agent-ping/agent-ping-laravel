# Changelog

All notable changes to the AgentPing Laravel SDK are documented here.
The format is based on
[Keep a Changelog](https://keepachangelog.com/), and this project
adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/agent-ping/agent-ping-laravel/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/agent-ping/agent-ping-laravel/releases/tag/v0.1.0
