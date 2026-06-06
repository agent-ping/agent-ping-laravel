<?php

namespace AgentPing\Laravel\Listeners;

use AgentPing\Laravel\AgentPing;

class RecordPromptStart
{
    public function __construct(private readonly AgentPing $sdk) {}

    public function handle(object $event): void
    {
        try {
            $invocationId = $event->invocationId ?? null;
            if (! is_string($invocationId) || $invocationId === '') {
                return;
            }
            $this->sdk->recordInvocationStart($invocationId);
        } catch (\Throwable $e) {
            $this->sdk->warner()->warn(
                'listener_error',
                'failed to record prompt start: ' . $e->getMessage()
            );
        }
    }
}
