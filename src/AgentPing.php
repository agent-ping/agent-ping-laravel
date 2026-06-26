<?php

namespace AgentPing\Laravel;

use AgentPing\Laravel\Client\HttpClient;
use AgentPing\Laravel\Exceptions\Paused;
use AgentPing\Laravel\Guard\GuardVerdict;
use AgentPing\Laravel\Queue\BoundedQueue;
use AgentPing\Laravel\Queue\FlushWorker;
use AgentPing\Laravel\Support\Ids;
use AgentPing\Laravel\Support\WarnOnce;
use Carbon\Carbon;

class AgentPing
{
    private readonly string $region;

    private ?Run $currentRun = null;

    private ?string $currentAgentName = null;

    /** @var array<string, Run> */
    private array $invocationRuns = [];

    /** @var array<string, float> */
    private array $invocationStarts = [];

    public function __construct(
        private readonly BoundedQueue $queue,
        private readonly FlushWorker $worker,
        private readonly HttpClient $client,
        private readonly HttpClient $controlClient,
        private readonly WarnOnce $warner,
        private readonly ?string $apiKey,
        private readonly string $defaultAgent = 'ai-agent',
    ) {
        $this->region = Ids::extractRegion($apiKey);
        if ($apiKey === null || $apiKey === '') {
            $warner->warn(
                'auth_error',
                'no API key provided; telemetry disabled. Set AGENTPING_API_KEY.'
            );
        }
    }

    public function region(): string
    {
        return $this->region;
    }

    public function isEnabled(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /**
     * The guard gate: a top-of-script safety net (guard-checks-spec). One
     * synchronous call to the control plane that returns a verdict or, in hard
     * mode, throws {@see Paused} on a block.
     *
     * Defaults fail closed: $mode "hard" throws on block, $onUnreachable
     * "block" refuses to run when the gate cannot be reached. A 429 is our
     * condition, not a safety signal: retried once then leans allow. An
     * inactive plan is a no-op allow plus a loud one-time warning.
     *
     * @param  'hard'|'soft'  $mode
     * @param  'block'|'allow'  $onUnreachable
     */
    public function guardCheck(
        ?string $customerRef = null,
        ?string $agent = null,
        ?string $function = null,
        ?string $environment = null,
        string $mode = 'hard',
        string $onUnreachable = 'block',
    ): GuardVerdict {
        $body = array_filter([
            'customer_ref' => $customerRef,
            'agent' => $agent,
            'function' => $function,
            'environment' => $environment,
        ], fn ($v) => $v !== null && $v !== '');

        if (! $this->isEnabled()) {
            return $this->guardUnreachable('not_initialized', $mode, $onUnreachable);
        }

        $result = $this->controlClient->postRaw('/v1/guard/check', $body);

        if ($result['status'] === 429) {
            usleep((int) (min($result['retry_after'] ?? 0.5, 1.0) * 1_000_000));
            $result = $this->controlClient->postRaw('/v1/guard/check', $body);
            if ($result['status'] === 429 || $result['status'] === 0) {
                // A throttle is our condition, not a safety signal: lean allow.
                return GuardVerdict::synthetic('allow');
            }
        }

        if ($result['status'] !== 200 || ! isset($result['body']['decision'])) {
            return $this->guardUnreachable('http_' . $result['status'], $mode, $onUnreachable);
        }

        $verdict = GuardVerdict::fromArray($result['body']);

        if (! $verdict->active) {
            $this->warner->warn(
                'guard_inactive_on_plan',
                'guard is inactive on your plan; this script is running UNGUARDED. Upgrade to the Team or Business plan to enable it.'
            );
        }

        if ($verdict->blocked() && $mode === 'hard') {
            throw new Paused($verdict);
        }

        return $verdict;
    }

    private function guardUnreachable(string $reason, string $mode, string $onUnreachable): GuardVerdict
    {
        $this->warner->warn(
            'guard_unreachable',
            "guard gate unreachable ({$reason}); applying on_unreachable={$onUnreachable}."
        );

        $verdict = $onUnreachable === 'block'
            ? GuardVerdict::synthetic('block', 'unreachable')
            : GuardVerdict::synthetic('allow');

        if ($verdict->blocked() && $mode === 'hard') {
            throw new Paused($verdict);
        }

        return $verdict;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function run(
        string $agent,
        ?string $customerId = null,
        ?string $feature = null,
        ?array $metadata = null,
        ?string $parentRunId = null,
    ): Run {
        $parent = $parentRunId ?? ($_ENV['AGENTPING_PARENT_RUN'] ?? getenv('AGENTPING_PARENT_RUN') ?: null);
        $run = new Run(
            sdk: $this,
            agent: $agent,
            customerId: $customerId,
            feature: $feature,
            metadata: $metadata,
            parentRunId: $parent !== false && $parent !== '' ? $parent : null,
        );
        $this->currentRun = $run;

        return $run;
    }

    public function currentRun(): ?Run
    {
        return $this->currentRun;
    }

    public function setCurrentRun(?Run $run): void
    {
        $this->currentRun = $run;
    }

    /**
     * Name to apply to auto-instrumented laravel/ai calls that aren't wrapped
     * in a run and use the anonymous agent() helper (which carries no class
     * name). Call once at the top of a request, job, or command; it is reset
     * automatically at the end of each. Pass null to clear.
     */
    public function useAgent(?string $name): void
    {
        $this->currentAgentName = $name !== null && $name !== '' ? $name : null;
    }

    public function currentAgentName(): ?string
    {
        return $this->currentAgentName;
    }

    /**
     * Clear the per-request/job scope (current run + agent name). Called by the
     * service provider on app termination and after each queued job so state
     * never leaks between requests or jobs in a long-running worker.
     */
    public function resetScope(): void
    {
        $this->currentRun = null;
        $this->currentAgentName = null;
    }

    /**
     * Agent name used for auto-instrumented laravel/ai calls when no run is
     * active and the call cannot be attributed to a named Agent class (e.g. the
     * anonymous agent() helper). Set per-app via AGENTPING_DEFAULT_AGENT.
     */
    public function defaultAgentName(): string
    {
        return $this->defaultAgent;
    }

    /**
     * Run a callback under a named agent. Opens a run and makes it the current
     * run, so any auto-instrumented laravel/ai calls inside the callback attach
     * to it; finishes the run (success, or failed on exception) and restores
     * the previous current run. Returns the callback's value.
     *
     * @template TReturn
     *
     * @param  \Closure(Run): TReturn  $callback
     * @param  array<string, mixed>|null  $metadata
     * @return TReturn
     */
    public function agent(
        string $name,
        \Closure $callback,
        ?string $customerId = null,
        ?string $feature = null,
        ?array $metadata = null,
    ): mixed {
        $previous = $this->currentRun;
        $run = $this->run($name, customerId: $customerId, feature: $feature, metadata: $metadata);

        try {
            $result = $callback($run);
            $run->finish('success');

            return $result;
        } catch (\Throwable $e) {
            $run->finish('failed');

            throw $e;
        } finally {
            $this->currentRun = $previous;
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function heartbeat(
        string $agent,
        string $status = 'ok',
        ?float $costUsd = null,
        ?int $durationMs = null,
        ?array $metadata = null,
    ): string {
        $id = Ids::newId('run', $this->region);
        $now = Carbon::now();
        $started = $durationMs !== null ? $now->copy()->subMilliseconds($durationMs) : $now;

        $body = [
            'id' => $id,
            'agent' => $agent,
            'status' => $status,
            'started_at' => Run::iso($started),
            'finished_at' => Run::iso($now),
        ];
        if ($costUsd !== null) {
            $body['cost_usd'] = $costUsd;
        }
        if ($metadata !== null && $metadata !== []) {
            $body['metadata'] = $metadata;
        }

        $this->enqueue('/v1/heartbeats', $body);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function enqueue(string $path, array $body): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        $this->queue->push([
            'path' => $path,
            'body' => $body,
            'attempts' => 0,
        ]);
    }

    public function flush(float $timeoutSeconds = 5.0): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        return $this->worker->flush($timeoutSeconds);
    }

    /**
     * @return array{queue_size: int, dropped_count: int, last_flush_at: ?Carbon, last_error: ?string}
     */
    public function status(): array
    {
        return [
            'queue_size' => $this->queue->size(),
            'dropped_count' => $this->queue->droppedCount(),
            'last_flush_at' => $this->worker->lastFlushAt(),
            'last_error' => $this->worker->lastError(),
        ];
    }

    public function recordInvocationStart(string $invocationId): void
    {
        $this->invocationStarts[$invocationId] = microtime(true);
    }

    public function takeInvocationStart(string $invocationId): ?float
    {
        if (! isset($this->invocationStarts[$invocationId])) {
            return null;
        }
        $start = $this->invocationStarts[$invocationId];
        unset($this->invocationStarts[$invocationId]);

        return $start;
    }

    public function bindInvocationRun(string $invocationId, Run $run): void
    {
        $this->invocationRuns[$invocationId] = $run;
    }

    public function takeInvocationRun(string $invocationId): ?Run
    {
        if (! isset($this->invocationRuns[$invocationId])) {
            return null;
        }
        $run = $this->invocationRuns[$invocationId];
        unset($this->invocationRuns[$invocationId]);

        return $run;
    }

    public function queue(): BoundedQueue
    {
        return $this->queue;
    }

    public function worker(): FlushWorker
    {
        return $this->worker;
    }

    public function client(): HttpClient
    {
        return $this->client;
    }

    public function warner(): WarnOnce
    {
        return $this->warner;
    }
}
