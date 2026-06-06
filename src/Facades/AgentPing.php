<?php

namespace AgentPing\Laravel\Facades;

use AgentPing\Laravel\AgentPing as AgentPingService;
use AgentPing\Laravel\Run;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Run run(string $agent, ?string $customerId = null, ?string $feature = null, ?array $metadata = null, ?string $parentRunId = null)
 * @method static mixed agent(string $name, \Closure $callback, ?string $customerId = null, ?string $feature = null, ?array $metadata = null)
 * @method static string defaultAgentName()
 * @method static string heartbeat(string $agent, string $status = 'ok', ?float $costUsd = null, ?int $durationMs = null, ?array $metadata = null)
 * @method static int flush(float $timeoutSeconds = 5.0)
 * @method static array status()
 * @method static string region()
 * @method static bool isEnabled()
 * @method static ?Run currentRun()
 * @method static void setCurrentRun(?Run $run)
 *
 * @see AgentPingService
 */
class AgentPing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AgentPingService::class;
    }
}
