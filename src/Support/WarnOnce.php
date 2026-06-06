<?php

namespace AgentPing\Laravel\Support;

use Illuminate\Support\Facades\Log;

class WarnOnce
{
    /** @var array<string, true> */
    private array $warned = [];

    public function warn(string $errorClass, string $message): void
    {
        if (isset($this->warned[$errorClass])) {
            return;
        }
        $this->warned[$errorClass] = true;
        try {
            Log::warning('[agentping] ' . $message, ['error_class' => $errorClass]);
        } catch (\Throwable) {
            // Logging itself must never crash user code.
        }
    }

    public function reset(): void
    {
        $this->warned = [];
    }
}
