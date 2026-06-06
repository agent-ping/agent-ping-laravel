<?php

namespace AgentPing\Laravel;

use AgentPing\Laravel\Client\HttpClient;
use AgentPing\Laravel\Queue\BoundedQueue;
use AgentPing\Laravel\Queue\FlushWorker;
use AgentPing\Laravel\Support\Ids;
use AgentPing\Laravel\Support\WarnOnce;
use Carbon\Carbon;

class AgentPing
{
    private readonly string $region;

    private ?Run $currentRun = null;

    /** @var array<string, Run> */
    private array $invocationRuns = [];

    /** @var array<string, float> */
    private array $invocationStarts = [];

    public function __construct(
        private readonly BoundedQueue $queue,
        private readonly FlushWorker $worker,
        private readonly HttpClient $client,
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
