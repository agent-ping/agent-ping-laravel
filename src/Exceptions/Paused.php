<?php

namespace AgentPing\Laravel\Exceptions;

use AgentPing\Laravel\Guard\GuardVerdict;
use RuntimeException;

/**
 * Thrown by AgentPing::guardCheck() in hard mode when the gate blocks
 * (guard-checks-spec). Carries the full verdict so the caller can see what
 * blocked and by how much.
 */
class Paused extends RuntimeException
{
    public function __construct(public readonly GuardVerdict $verdict)
    {
        $reason = $verdict->blockedBy === [] ? 'a guard rule' : implode(', ', $verdict->blockedBy);
        parent::__construct("AgentPing guard blocked this run ({$reason}).");
    }
}
