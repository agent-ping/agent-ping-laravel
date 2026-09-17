<?php

namespace AgentPing\Laravel\Listeners;

/**
 * ProviderFailedOver / AgentFailedOver: the SDK gave up on one provider and
 * is retrying the prompt on the next. Recorded as a step so the timeline
 * explains why the priced llm_call that follows names a different provider.
 * Not an error: the prompt may still succeed.
 */
class HandleProviderFailedOver extends AiSdkListener
{
    protected function eventLabel(): string
    {
        return 'ProviderFailedOver';
    }

    protected function process(object $event): void
    {
        $exception = $event->exception ?? null;

        $data = ['kind' => 'provider_failover']
            + $this->providerAndModel(null, $event->provider ?? null, $event->model ?? null);
        if ($exception instanceof \Throwable) {
            $data['reason'] = $exception->getMessage();
            $data['exception'] = $exception::class;
        }

        $invocationId = $event->invocationId ?? null;
        if (is_string($invocationId) && $invocationId !== '') {
            [$run] = $this->runFor($invocationId, $event->agent ?? null);
        } else {
            // The base ProviderFailedOver carries no invocation id, so it can
            // only be attributed to a run the app opened itself.
            $run = $this->sdk->currentRun();
            if ($run === null) {
                return;
            }
        }

        $run->event('step', $data);
    }
}
