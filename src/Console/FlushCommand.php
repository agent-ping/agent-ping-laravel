<?php

namespace AgentPing\Laravel\Console;

use AgentPing\Laravel\AgentPing;
use Illuminate\Console\Command;

class FlushCommand extends Command
{
    protected $signature = 'agentping:flush {--timeout=5 : Max seconds to flush}';

    protected $description = 'Flush the in-memory AgentPing telemetry queue to the API.';

    public function handle(AgentPing $sdk): int
    {
        $timeout = (float) $this->option('timeout');
        $sent = $sdk->flush($timeout);
        $status = $sdk->status();
        $this->line(sprintf(
            '[agentping] flushed=%d remaining=%d dropped=%d last_error=%s',
            $sent,
            $status['queue_size'],
            $status['dropped_count'],
            $status['last_error'] ?? 'none',
        ));

        return self::SUCCESS;
    }
}
