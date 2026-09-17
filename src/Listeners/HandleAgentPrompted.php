<?php

namespace AgentPing\Laravel\Listeners;

/**
 * AgentPrompted / AgentStreamed: the whole prompt has finished. Emits one
 * llm_call with the summed usage unless StepCompleted already emitted one
 * per step, and closes the synthetic run if this invocation opened one.
 */
class HandleAgentPrompted extends AiSdkListener
{
    private bool $stream = false;

    public function handle(object $event, bool $stream = false): void
    {
        $this->stream = $stream;
        parent::handle($event);
    }

    protected function eventLabel(): string
    {
        return $this->stream ? 'AgentStreamed' : 'AgentPrompted';
    }

    protected function process(object $event): void
    {
        $invocationId = $event->invocationId ?? null;
        $response = $event->response ?? null;
        $prompt = $event->prompt ?? null;
        if (! is_string($invocationId) || $invocationId === '' || $response === null) {
            return;
        }

        $start = $this->sdk->takeInvocationStart($invocationId);
        $stepped = $this->sdk->takeInvocationSteps($invocationId);

        [$run, $synthetic] = $this->runFor($invocationId, $prompt);
        $this->sdk->takeInvocationRun($invocationId);

        if (! $stepped) {
            $data = $this->providerAndModel($response->meta ?? null)
                + $this->usageData($response->usage ?? null);

            if ($start !== null) {
                $data['latency_ms'] = (int) max(0, round((microtime(true) - $start) * 1000));
            }
            if ($this->stream) {
                $data['stream'] = true;
            }

            $run->event('llm_call', $data);
        }

        if ($synthetic) {
            $this->finishSynthetic($run, 'success');
        }
    }
}
