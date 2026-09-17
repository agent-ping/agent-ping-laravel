<?php

namespace AgentPing\Laravel\Listeners;

use AgentPing\Laravel\AgentPing;
use AgentPing\Laravel\Run;
use Illuminate\Support\Str;

/**
 * Shared plumbing for the laravel/ai event listeners: swallow every
 * exception (telemetry must never break the app), pick the run an event
 * belongs to, and name synthetic runs consistently.
 *
 * Events are read as loosely typed objects on purpose. laravel/ai is only a
 * suggested dependency, so the package (and its tests) cannot reference the
 * SDK's classes directly.
 */
abstract class AiSdkListener
{
    public function __construct(protected readonly AgentPing $sdk) {}

    public function handle(object $event): void
    {
        try {
            if (! $this->sdk->isEnabled()) {
                return;
            }
            $this->process($event);
        } catch (\Throwable $e) {
            $this->sdk->warner()->warn(
                'listener_error',
                'failed to handle ' . $this->eventLabel() . ': ' . $e->getMessage()
            );
        }
    }

    abstract protected function process(object $event): void;

    abstract protected function eventLabel(): string;

    /**
     * The run this invocation's events attach to, in priority order: the
     * synthetic run an earlier event of the same invocation created, the run
     * the app opened itself, or a new synthetic run. The flag says whether
     * the run is ours to finish.
     *
     * @return array{0: Run, 1: bool}
     */
    protected function runFor(string $invocationId, mixed $agent): array
    {
        $bound = $this->sdk->invocationRun($invocationId);
        if ($bound !== null) {
            return [$bound, true];
        }

        $current = $this->sdk->currentRun();
        if ($current !== null) {
            return [$current, false];
        }

        $run = $this->sdk->run($this->agentSlug($agent), metadata: ['invocation_id' => $invocationId]);
        $this->sdk->bindInvocationRun($invocationId, $run);

        return [$run, true];
    }

    /**
     * Finish a synthetic run and drop it from the current-run slot so the
     * next auto-instrumented prompt starts fresh.
     */
    protected function finishSynthetic(Run $run, string $status): void
    {
        $run->finish($status);
        if ($this->sdk->currentRun() === $run) {
            $this->sdk->setCurrentRun(null);
        }
    }

    /**
     * Resolve the agent name for a synthetic run, in priority order: an
     * explicit AgentPing::useAgent() name, then a named laravel/ai Agent class,
     * then the configured default. The anonymous agent() helper has no class
     * identity, so it falls through to useAgent()/default.
     *
     * Accepts an Agent instance, an Agent class name, or a prompt object
     * carrying ->agent.
     */
    protected function agentSlug(mixed $agent): string
    {
        return $this->sdk->currentAgentName()
            ?? $this->namedAgentSlug($agent)
            ?? $this->sdk->defaultAgentName();
    }

    private function namedAgentSlug(mixed $agent): ?string
    {
        if (is_object($agent) && property_exists($agent, 'agent')) {
            $agent = $agent->agent;
        }

        $class = match (true) {
            is_string($agent) => $agent,
            // A bare stdClass is a prompt with no agent on it, not an agent.
            is_object($agent) && ! $agent instanceof \stdClass => $agent::class,
            default => null,
        };
        if ($class === null) {
            return null;
        }

        $base = class_basename($class);
        if ($base === '' || $base === 'AnonymousAgent') {
            return null;
        }

        return Str::snake($base);
    }

    /**
     * Token counts from a laravel/ai Usage object, with the optional counters
     * left out when zero so the payload stays small.
     *
     * input_tokens is the gross prompt size. Ingest subtracts the cached
     * split itself when it prices a call, so it must include the cached
     * prefix. Anthropic reports input_tokens net of the cache and Prism
     * passes that through as promptTokens; every other provider laravel/ai
     * ships already reports gross.
     *
     * @return array<string, int>
     */
    protected function usageData(mixed $usage, ?string $provider = null): array
    {
        $inputTokens = (int) ($usage->promptTokens ?? 0);
        $cacheRead = (int) ($usage->cacheReadInputTokens ?? 0);
        $cacheWrite = (int) ($usage->cacheWriteInputTokens ?? 0);

        if ($provider !== null && str_contains(strtolower($provider), 'anthropic')) {
            $inputTokens += $cacheRead + $cacheWrite;
        }

        $data = [
            'input_tokens' => $inputTokens,
            'output_tokens' => (int) ($usage->completionTokens ?? 0),
        ];

        if ($cacheRead > 0) {
            $data['cached_input_tokens'] = $cacheRead;
        }
        if ($cacheWrite > 0) {
            $data['cache_creation_input_tokens'] = $cacheWrite;
        }
        $reasoning = (int) ($usage->reasoningTokens ?? 0);
        if ($reasoning > 0) {
            $data['reasoning_tokens'] = $reasoning;
        }

        return $data;
    }

    /**
     * Provider and model for an llm_call, preferring what the response
     * reports (the provider actually used, after any failover) over what
     * the event was dispatched with.
     *
     * @return array{provider: string, model: string}
     */
    protected function providerAndModel(mixed $meta, mixed $provider = null, mixed $model = null): array
    {
        $providerName = null;
        if (is_object($meta) && isset($meta->provider) && is_string($meta->provider)) {
            $providerName = $meta->provider;
        } elseif (is_object($provider) && is_callable([$provider, 'name'])) {
            $name = $provider->name();
            $providerName = is_string($name) ? $name : null;
        } elseif (is_string($provider)) {
            $providerName = $provider;
        }

        $modelName = null;
        if (is_object($meta) && isset($meta->model) && is_string($meta->model)) {
            $modelName = $meta->model;
        } elseif (is_string($model) && $model !== '') {
            $modelName = $model;
        }

        return [
            'provider' => $providerName !== null && $providerName !== '' ? strtolower($providerName) : 'unknown',
            'model' => $modelName ?? 'unknown',
        ];
    }

    /**
     * A tool's public name: its name() when it declares one, otherwise the
     * class basename, matching laravel/ai's own ToolNameResolver.
     */
    protected function toolName(mixed $tool): string
    {
        if (is_object($tool) && is_callable([$tool, 'name'])) {
            $name = $tool->name();
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return is_object($tool) ? class_basename($tool) : 'tool';
    }

    /**
     * Render a tool argument list or result as a bounded string for the
     * event payload. Objects and arrays become JSON; anything else is cast.
     */
    protected function stringify(mixed $value, int $max = 4000): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        if (is_string($value) || $value instanceof \Stringable) {
            $text = (string) $value;
        } elseif (is_scalar($value)) {
            $text = var_export($value, true);
        } else {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $text = is_string($encoded) ? $encoded : '';
        }

        if ($text === '') {
            return null;
        }

        return Str::limit($text, $max, '...');
    }

    protected function latencyMs(mixed $time): ?int
    {
        return is_int($time) || is_float($time) ? (int) max(0, round($time)) : null;
    }
}
