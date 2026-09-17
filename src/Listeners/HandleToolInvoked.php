<?php

namespace AgentPing\Laravel\Listeners;

/**
 * ToolInvoked / ToolFailed: a tool the model asked for has run. Emits a
 * tool_call with the tool name, latency and (when capture is on) the
 * arguments and result, so the run timeline shows what the agent did
 * between model calls.
 */
class HandleToolInvoked extends AiSdkListener
{
    private bool $failed = false;

    public function handle(object $event, bool $failed = false): void
    {
        $this->failed = $failed;
        parent::handle($event);
    }

    protected function eventLabel(): string
    {
        return $this->failed ? 'ToolFailed' : 'ToolInvoked';
    }

    protected function process(object $event): void
    {
        $invocationId = $event->invocationId ?? null;
        if (! is_string($invocationId) || $invocationId === '') {
            return;
        }

        $data = [
            'tool' => $this->toolName($event->tool ?? null),
            'status' => $this->failed ? 'error' : 'success',
        ];
        if (isset($event->toolInvocationId) && is_string($event->toolInvocationId)) {
            $data['tool_invocation_id'] = $event->toolInvocationId;
        }
        if (($latency = $this->latencyMs($event->time ?? null)) !== null) {
            $data['latency_ms'] = $latency;
        }

        if ($this->captureEnabled()) {
            $max = $this->captureMaxChars();
            if (($input = $this->stringify($event->arguments ?? null, $max)) !== null) {
                $data['input'] = $input;
            }
            if (! $this->failed && ($output = $this->stringify($event->result ?? null, $max)) !== null) {
                $data['output'] = $output;
            }
        }

        if ($this->failed) {
            $exception = $event->exception ?? null;
            $data['error'] = $exception instanceof \Throwable ? $exception->getMessage() : 'tool failed';
            if ($exception instanceof \Throwable) {
                $data['exception'] = $exception::class;
            }
        }

        [$run] = $this->runFor($invocationId, $event->agent ?? null);
        $run->event('tool_call', $data);
    }

    private function captureEnabled(): bool
    {
        return (bool) config('agentping.capture_tool_payloads', true);
    }

    private function captureMaxChars(): int
    {
        return max(0, (int) config('agentping.tool_payload_max_chars', 4000));
    }
}
