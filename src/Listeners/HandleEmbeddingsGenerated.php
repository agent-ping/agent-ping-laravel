<?php

namespace AgentPing\Laravel\Listeners;

use AgentPing\Laravel\AgentPing;
use AgentPing\Laravel\Run;

class HandleEmbeddingsGenerated
{
    public function __construct(private readonly AgentPing $sdk) {}

    public function handle(object $event): void
    {
        try {
            $this->process($event);
        } catch (\Throwable $e) {
            $this->sdk->warner()->warn(
                'listener_error',
                'failed to handle EmbeddingsGenerated: ' . $e->getMessage()
            );
        }
    }

    private function process(object $event): void
    {
        if (! $this->sdk->isEnabled()) {
            return;
        }

        $invocationId = $event->invocationId ?? null;
        $provider = $event->provider ?? null;
        $model = $event->model ?? null;
        $response = $event->response ?? null;
        if (! is_string($invocationId) || $invocationId === '') {
            return;
        }

        $providerStr = 'unknown';
        if (is_string($provider)) {
            $providerStr = strtolower($provider);
        } elseif (is_object($provider)) {
            $providerStr = strtolower(class_basename($provider));
        }

        $usage = $response->usage ?? null;
        $inputTokens = (int) ($usage->promptTokens ?? 0);

        $data = [
            'provider' => $providerStr,
            'model' => is_string($model) ? $model : 'unknown',
            'input_tokens' => $inputTokens,
            'output_tokens' => 0,
            'kind' => 'embedding',
        ];

        $start = $this->sdk->takeInvocationStart($invocationId);
        if ($start !== null) {
            $data['latency_ms'] = (int) max(0, round((microtime(true) - $start) * 1000));
        }

        $run = $this->sdk->currentRun();
        $synthetic = false;
        if ($run === null) {
            $synthetic = true;
            $run = $this->sdk->run('embeddings_' . substr($invocationId, 0, 8), metadata: ['invocation_id' => $invocationId]);
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
}
