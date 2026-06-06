<?php

namespace AgentPing\Laravel\Tests\Feature;

use AgentPing\Laravel\Facades\AgentPing;
use AgentPing\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class HeartbeatTest extends TestCase
{
    public function test_heartbeat_returns_well_formed_run_id_immediately(): void
    {
        Http::fake();
        $id = AgentPing::heartbeat('daily-summary',
            status: 'ok',
            costUsd: 0.084,
            durationMs: 12300,
            metadata: ['items' => 47],
        );
        $this->assertSame(1, preg_match('/^run_eu_[0-9a-f]{32}$/', $id));
    }

    public function test_heartbeat_sends_expected_payload(): void
    {
        Http::fake([
            '*' => Http::response([], 202),
        ]);

        AgentPing::heartbeat('daily-summary',
            status: 'ok',
            costUsd: 0.084,
            durationMs: 12300,
            metadata: ['items' => 47],
        );

        AgentPing::flush(5.0);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/v1/heartbeats')
                && $request['agent'] === 'daily-summary'
                && $request['status'] === 'ok'
                && abs(((float) $request['cost_usd']) - 0.084) < 1e-9
                && $request['metadata'] === ['items' => 47]
                && preg_match('/^run_eu_[0-9a-f]{32}$/', $request['id']) === 1
                && isset($request['started_at'], $request['finished_at']);
        });
    }
}
