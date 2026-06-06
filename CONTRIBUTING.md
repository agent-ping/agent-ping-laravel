# Contributing

Thanks for considering a contribution to the AgentPing Laravel SDK.

## Reporting a bug

Open a GitHub issue. Useful issues include:

- The version (`composer show agentping/laravel`).
- Your Laravel and PHP versions (`php artisan --version`, `php -v`).
- A minimal reproduction. Snip the surrounding application code; we
  only need the AgentPing calls and the relevant config.
- What you expected to happen, what actually happened.
- Output of `AgentPing::status()` if the bug is about telemetry not
  landing.

Security issues should not go on the public tracker. Email
`security@agentping.io` instead.

## Proposing a feature

Open an issue before opening a PR. Most feature work touches the wire
contract with the AgentPing ingest server, so we want to design the
interface together before you spend time implementing it.

## Local development

```bash
git clone https://github.com/agent-ping/agent-ping-laravel
cd agentping-laravel
composer install
vendor/bin/phpunit --no-coverage
```

PHP 8.2 or later. The SDK supports Laravel 10, 11, 12, and 13; the
test suite uses Orchestra Testbench to boot a minimal application
without requiring a full Laravel install.

## Tests

```bash
vendor/bin/phpunit --no-coverage                  # full suite
vendor/bin/phpunit --filter=HeartbeatTest          # one file
vendor/bin/phpunit --filter='it sends'             # filter by name
```

We expect every PR to add or update tests. The HTTP layer is faked via
Laravel's `Http::fake()` helper, so tests do not require credentials
or a live ingest endpoint.

## Style

- `vendor/bin/pint` for formatting. Runs in CI.
- Strict types where practical; no `mixed` unless interfacing with
  Laravel's loose-typed config.
- No em-dashes in source, PHPDoc, comments, or commit messages. Use
  commas, semicolons, or sentence breaks. This is a project-wide rule.

## Commit messages and PRs

- Subject in imperative mood (`add terminating-middleware flush`, not
  `added terminating-middleware flush`).
- One topic per PR. Bug fixes, refactors, and new features should not
  be bundled.
- If the PR changes the wire contract with ingest, link the matching
  issue in the AgentPing platform tracker.

## Releasing

Maintainers only. Release flow lives in
`agent-ping/LAUNCH.md` in the AgentPing platform monorepo.
