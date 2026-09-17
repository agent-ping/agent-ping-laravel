<?php

namespace AgentPing\Laravel\Listeners;

/**
 * StepCompleted: one model call inside a multi-step (tool-using) prompt.
 * Emits an llm_call per step so a prompt that loops through four tool
 * rounds shows four priced calls with their own latency, and tells the
 * AgentPrompted listener not to price the summed usage again.
 */
class HandleStepCompleted extends AiSdkListener
{
    protected function eventLabel(): string
    {
        return 'StepCompleted';
    }

    protected function process(object $event): void
    {
        $invocationId = $event->invocationId ?? null;
        $response = $event->response ?? null;
        if (! is_string($invocationId) || $invocationId === '' || $response === null) {
            return;
        }

        $data = $this->providerAndModel($response->meta ?? null, $event->provider ?? null, $event->model ?? null);
        $data += $this->usageData($response->usage ?? null, $data['provider']);

        if (($latency = $this->latencyMs($event->time ?? null)) !== null) {
            $data['latency_ms'] = $latency;
        }
        if (isset($event->stepNumber)) {
            $data['step'] = (int) $event->stepNumber;
        }
        if (isset($event->isFinalStep)) {
            $data['final'] = (bool) $event->isFinalStep;
        }
        $finishReason = $response->finishReason ?? null;
        if (is_object($finishReason) && isset($finishReason->value)) {
            $data['finish_reason'] = (string) $finishReason->value;
        } elseif (is_string($finishReason)) {
            $data['finish_reason'] = $finishReason;
        }
        $toolCalls = $response->toolCalls ?? null;
        if (is_array($toolCalls) && $toolCalls !== []) {
            $data['tool_calls'] = count($toolCalls);
        }

        [$run] = $this->runFor($invocationId, $event->agent ?? null);
        $run->event('llm_call', $data);
        $this->sdk->markInvocationSteps($invocationId);
    }
}
