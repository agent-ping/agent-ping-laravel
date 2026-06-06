<?php

namespace AgentPing\Laravel\Listeners;

use AgentPing\Laravel\AgentPing;
use AgentPing\Laravel\Run;
use Illuminate\Support\Str;

class HandleAgentPrompted
{
    public function __construct(private readonly AgentPing $sdk) {}

    public function handle(object $event): void
    {
        try {
            $this->process($event);
        } catch (\Throwable $e) {
            $this->sdk->warner()->warn(
                'listener_error',
                'failed to handle AgentPrompted: ' . $e->getMessage()
            );
        }
    }

    private function process(object $event): void
    {
        if (! $this->sdk->isEnabled()) {
            return;
        }

        $invocationId = $event->invocationId ?? null;
        $response = $event->response ?? null;
        $prompt = $event->prompt ?? null;
        if (! is_string($invocationId) || $invocationId === '' || $response === null) {
            return;
        }

        $usage = $response->usage ?? null;
        $meta = $response->meta ?? null;

        $provider = null;
        if ($meta !== null && isset($meta->provider)) {
            $provider = is_string($meta->provider) ? strtolower($meta->provider) : null;
        }
        $model = null;
        if ($meta !== null && isset($meta->model)) {
            $model = is_string($meta->model) ? $meta->model : null;
        }

        $data = [
            'provider' => $provider ?? 'unknown',
            'model' => $model ?? 'unknown',
            'input_tokens' => (int) ($usage->promptTokens ?? 0),
            'output_tokens' => (int) ($usage->completionTokens ?? 0),
        ];

        $cacheRead = (int) ($usage->cacheReadInputTokens ?? 0);
        if ($cacheRead > 0) {
            $data['cached_input_tokens'] = $cacheRead;
        }
        $cacheWrite = (int) ($usage->cacheWriteInputTokens ?? 0);
        if ($cacheWrite > 0) {
            $data['cache_creation_input_tokens'] = $cacheWrite;
        }
        $reasoning = (int) ($usage->reasoningTokens ?? 0);
        if ($reasoning > 0) {
            $data['reasoning_tokens'] = $reasoning;
        }

        $start = $this->sdk->takeInvocationStart($invocationId);
        if ($start !== null) {
            $data['latency_ms'] = (int) max(0, round((microtime(true) - $start) * 1000));
        }

        $run = $this->sdk->currentRun();
        $synthetic = false;
        if ($run === null) {
            $synthetic = true;
            $agentSlug = $this->deriveAgentSlug($prompt, $invocationId);
            $run = $this->sdk->run($agentSlug, metadata: ['invocation_id' => $invocationId]);
            $this->sdk->bindInvocationRun($invocationId, $run);
        }

        $run->event('llm_call', $data);

        if ($synthetic && $run instanceof Run) {
            $run->finish('success');
            if ($this->sdk->currentRun() === $run) {
                $this->sdk->setCurrentRun(null);
            }
        }
    }

    private function deriveAgentSlug(mixed $prompt, string $invocationId): string
    {
        if (is_object($prompt)) {
            $agentClass = null;
            if (isset($prompt->agent) && is_string($prompt->agent)) {
                $agentClass = $prompt->agent;
            } elseif (method_exists($prompt, 'agent')) {
                try {
                    $maybe = $prompt->agent();
                    if (is_string($maybe)) {
                        $agentClass = $maybe;
                    } elseif (is_object($maybe)) {
                        $agentClass = $maybe::class;
                    }
                } catch (\Throwable) {
                    // ignore
                }
            }
            if ($agentClass !== null && $agentClass !== '') {
                $base = class_basename($agentClass);

                return Str::snake($base);
            }
        }

        return 'ai_invocation_' . substr($invocationId, 0, 8);
    }
}
