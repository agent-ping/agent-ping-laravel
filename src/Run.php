<?php

namespace AgentPing\Laravel;

use AgentPing\Laravel\Support\Ids;
use Carbon\Carbon;

class Run
{
    private readonly string $id;

    private readonly string $region;

    private readonly string $startedAt;

    private bool $finished = false;

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        private readonly AgentPing $sdk,
        public readonly string $agent,
        public readonly ?string $customerId = null,
        public readonly ?string $feature = null,
        public readonly ?array $metadata = null,
        public readonly ?string $parentRunId = null,
        /**
         * Close the run automatically once no event has arrived for this long
         * (60-86400 seconds). For callers that cannot be relied on to finish the
         * run, and it makes the run resumable: a later event reopens it.
         */
        public readonly ?int $idleTimeoutSeconds = null,
        /**
         * What that silence meant. The server defaults to "timeout" -- we stopped
         * waiting, we do not know how it went. A chat should pass "success".
         */
        public readonly ?string $idleTimeoutStatus = null,
        /** @var array{0: string, 1: string}|null Reuse a known run id and start time. */
        ?array $resume = null,
    ) {
        $this->region = $sdk->region();
        $this->id = $resume[0] ?? Ids::newId('run', $this->region);
        $this->startedAt = $resume[1] ?? self::iso(Carbon::now());

        $body = [
            'id' => $this->id,
            'agent' => $agent,
            'started_at' => $this->startedAt,
        ];
        if ($customerId !== null) {
            $body['customer_id'] = $customerId;
        }
        if ($feature !== null) {
            $body['feature'] = $feature;
        }
        if ($metadata !== null && $metadata !== []) {
            $body['metadata'] = $metadata;
        }
        if ($parentRunId !== null && $parentRunId !== '') {
            $body['parent_run_id'] = $parentRunId;
        }
        if ($idleTimeoutSeconds !== null) {
            $body['idle_timeout_seconds'] = $idleTimeoutSeconds;
            if ($idleTimeoutStatus !== null) {
                $body['idle_timeout_status'] = $idleTimeoutStatus;
            }
        }

        $sdk->enqueue('/v1/runs', $body);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function agent(): string
    {
        return $this->agent;
    }

    public function startedAt(): string
    {
        return $this->startedAt;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    /**
     * Append an event to the run.
     *
     * @param  array<string, mixed>|null  $data
     * @param  int|null  $turn  Which exchange of a conversation this belongs to,
     *                          counting from 1. One turn is often several events --
     *                          call the model, run a tool, call it again -- and this
     *                          is what groups them. Omit outside a conversation.
     */
    public function event(string $type, ?array $data = null, ?int $turn = null): string
    {
        $evtId = Ids::newId('evt', $this->region);
        $entry = [
            'external_id' => $evtId,
            'type' => $type,
            'ts' => self::iso(Carbon::now()),
            'data' => $data ?? [],
        ];
        if ($turn !== null) {
            $entry['turn'] = $turn;
        }
        $this->sdk->enqueue('/v1/runs/' . $this->id . '/events', [
            'events' => [$entry],
        ]);

        return $evtId;
    }

    /**
     * @param  array<string, mixed>|null  $output
     * @param  array<string, mixed>|null  $scores
     */
    public function finish(string $status = 'success', ?array $output = null, ?array $scores = null): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;

        $body = [
            'status' => $status,
            'finished_at' => self::iso(Carbon::now()),
        ];
        if ($output !== null) {
            $body['output'] = $output;
        }
        if ($scores !== null) {
            $body['scores'] = $scores;
        }
        $this->sdk->enqueue('/v1/runs/' . $this->id . '/finish', $body);
    }

    public static function iso(Carbon $when): string
    {
        return $when->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
