<?php

namespace AgentPing\Laravel\Tests\Feature;

use AgentPing\Laravel\Exceptions\Paused;
use AgentPing\Laravel\Facades\AgentPing as AgentPingFacade;
use AgentPing\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GuardCheckTest extends TestCase
{
    /** @return array<string, mixed> */
    private function verdict(string $decision, array $extra = []): array
    {
        return array_merge([
            'decision' => $decision,
            'checked_at' => '2026-06-15T09:14:22Z',
            'blocked_by' => $decision === 'block' ? ['grl_eu_x'] : [],
            'guard_check_id' => $decision === 'block' ? 'gck_eu_1' : null,
            'rules' => [],
            'stale_as_of' => '2026-06-15T09:13:50Z',
            'active' => true,
            'inactive_reason' => null,
        ], $extra);
    }

    public function test_allow_returns_a_verdict(): void
    {
        Http::fake(['*' => Http::response($this->verdict('allow'), 200)]);

        $v = AgentPingFacade::guardCheck(agent: 'nightly');

        $this->assertSame('allow', $v->decision);
        $this->assertFalse($v->blocked());
    }

    public function test_hard_mode_throws_paused_on_block(): void
    {
        Http::fake(['*' => Http::response($this->verdict('block'), 200)]);

        try {
            AgentPingFacade::guardCheck(agent: 'nightly');
            $this->fail('expected Paused');
        } catch (Paused $e) {
            $this->assertTrue($e->verdict->blocked());
            $this->assertSame(['grl_eu_x'], $e->verdict->blockedBy);
        }
    }

    public function test_soft_mode_returns_the_block_verdict(): void
    {
        Http::fake(['*' => Http::response($this->verdict('block'), 200)]);

        $v = AgentPingFacade::guardCheck(agent: 'nightly', mode: 'soft');

        $this->assertTrue($v->blocked());
        $this->assertSame('gck_eu_1', $v->guardCheckId);
    }

    public function test_the_call_targets_the_control_plane_with_the_dimensions(): void
    {
        Http::fake(['*' => Http::response($this->verdict('allow'), 200)]);

        AgentPingFacade::guardCheck(customerRef: 'cus_abc', agent: 'nightly', function: 'run');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://agentping.io/v1/guard/check'
                && ($body['customer_ref'] ?? null) === 'cus_abc'
                && ($body['agent'] ?? null) === 'nightly'
                && ($body['function'] ?? null) === 'run';
        });
    }

    public function test_an_inactive_plan_warns_and_allows(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response($this->verdict('allow', ['active' => false, 'inactive_reason' => 'tier']), 200)]);

        $v = AgentPingFacade::guardCheck(agent: 'nightly');

        $this->assertSame('allow', $v->decision);
        $this->assertFalse($v->active);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => is_string($message) && str_contains(strtolower($message), 'unguarded'))
            ->once();
    }

    public function test_unreachable_fails_closed_by_default(): void
    {
        Http::fake(['*' => Http::response([], 503)]);

        $this->expectException(Paused::class);
        AgentPingFacade::guardCheck(agent: 'nightly');
    }

    public function test_unreachable_can_fail_open(): void
    {
        Http::fake(['*' => Http::response([], 503)]);

        $v = AgentPingFacade::guardCheck(agent: 'nightly', onUnreachable: 'allow');

        $this->assertSame('allow', $v->decision);
    }

    public function test_a_429_leans_allow_even_when_fail_closed(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => 'rate_limited'], 429, ['Retry-After' => '0'])
                ->push(['error' => 'rate_limited'], 429, ['Retry-After' => '0']),
        ]);

        $v = AgentPingFacade::guardCheck(agent: 'nightly', onUnreachable: 'block');

        $this->assertSame('allow', $v->decision);
        Http::assertSentCount(2); // one retry
    }
}
