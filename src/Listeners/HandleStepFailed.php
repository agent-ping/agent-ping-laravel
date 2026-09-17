<?php

namespace AgentPing\Laravel\Listeners;

/**
 * StepFailed: one model call inside a prompt threw. The prompt may still
 * recover through retry or failover, so this records a failed llm_call on
 * the run and leaves the run itself to AgentPrompted or AgentFailed.
 */
class HandleStepFailed extends AiSdkListener
{
    protected function eventLabel(): string
    {
        return 'StepFailed';
    }

    protected function process(object $event): void
    {
        $invocationId = $event->invocationId ?? null;
        if (! is_string($invocationId) || $invocationId === '') {
            return;
        }

        $exception = $event->exception ?? null;

        $data = $this->providerAndModel(null, $event->provider ?? null, $event->model ?? null) + [
            'status' => 'error',
            'error' => $exception instanceof \Throwable ? $exception->getMessage() : 'step failed',
        ];
        if ($exception instanceof \Throwable) {
            $data['exception'] = $exception::class;
        }
        if (($latency = $this->latencyMs($event->time ?? null)) !== null) {
            $data['latency_ms'] = $latency;
        }
        if (isset($event->stepNumber)) {
            $data['step'] = (int) $event->stepNumber;
        }

        [$run] = $this->runFor($invocationId, $event->agent ?? null);
        $run->event('llm_call', $data);
        $this->sdk->markInvocationSteps($invocationId);
    }
}
