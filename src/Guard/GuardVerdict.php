<?php

namespace AgentPing\Laravel\Guard;

use AgentPing\Laravel\Exceptions\Paused;

/**
 * The full server verdict (guard-checks-spec, "API contract"). Returned by
 * guardCheck in soft mode and carried by the {@see Paused}
 * exception in hard mode.
 */
class GuardVerdict
{
    /**
     * @param  array<int, string>  $blockedBy
     * @param  array<int, array<string, mixed>>  $rules
     */
    public function __construct(
        public readonly string $decision,
        public readonly array $blockedBy = [],
        public readonly array $rules = [],
        public readonly ?string $staleAsOf = null,
        public readonly bool $active = true,
        public readonly ?string $inactiveReason = null,
        public readonly ?string $guardCheckId = null,
        public readonly ?string $checkedAt = null,
    ) {}

    public function blocked(): bool
    {
        return $this->decision === 'block';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            decision: is_string($data['decision'] ?? null) ? $data['decision'] : 'allow',
            blockedBy: is_array($data['blocked_by'] ?? null) ? array_values($data['blocked_by']) : [],
            rules: is_array($data['rules'] ?? null) ? array_values($data['rules']) : [],
            staleAsOf: $data['stale_as_of'] ?? null,
            active: ($data['active'] ?? true) !== false,
            inactiveReason: $data['inactive_reason'] ?? null,
            guardCheckId: $data['guard_check_id'] ?? null,
            checkedAt: $data['checked_at'] ?? null,
        );
    }

    public static function synthetic(string $decision, string ...$blockedBy): self
    {
        return new self(decision: $decision, blockedBy: array_values($blockedBy));
    }
}
