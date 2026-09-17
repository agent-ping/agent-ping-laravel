<?php

namespace AgentPing\Laravel\Listeners;

/**
 * AgentFailed: the prompt threw after retries and failover were exhausted.
 * Records an error event on the run and, when this invocation opened a
 * synthetic run, finishes it as an error instead of leaving it open.
 */
class HandleAgentFailed extends AiSdkListener
{
    protected function eventLabel(): string
    {
        return 'AgentFailed';
    }

    protected function process(object $event): void
    {
        $invocationId = $event->invocationId ?? null;
        if (! is_string($invocationId) || $invocationId === '') {
            return;
        }

        $exception = $event->exception ?? null;
        $start = $this->sdk->takeInvocationStart($invocationId);
        $this->sdk->takeInvocationSteps($invocationId);

        [$run, $synthetic] = $this->runFor($invocationId, $event->prompt ?? null);
        $this->sdk->takeInvocationRun($invocationId);

        $data = [
            'error' => $exception instanceof \Throwable ? $exception->getMessage() : 'agent failed',
        ];
        if ($exception instanceof \Throwable) {
            $data['exception'] = $exception::class;
        }
        if ($start !== null) {
            $data['latency_ms'] = (int) max(0, round((microtime(true) - $start) * 1000));
        }

        $run->event('error', $data);

        if ($synthetic) {
            $this->finishSynthetic($run, 'error');
        }
    }
}
