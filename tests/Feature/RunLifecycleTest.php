<?php

namespace AgentPing\Laravel\Tests\Feature;

use AgentPing\Laravel\AgentPing;
use AgentPing\Laravel\Facades\AgentPing as AgentPingFacade;
use AgentPing\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class RunLifecycleTest extends TestCase
{
    public function test_run_id_is_available_immediately_and_well_formed(): void
    {
        Http::fake();
        $run = AgentPingFacade::run('support-triage');
        $this->assertSame(1, preg_match('/^run_eu_[0-9a-f]{32}$/', $run->id()));
    }

    public function test_run_enqueues_initial_post_to_v1_runs(): void
    {
        Http::fake();
        AgentPingFacade::run('support-triage',
            customerId: 'acme',
            feature: 'ticket-routing',
            metadata: ['ticket_id' => 'T-12345'],
        );

        /** @var AgentPing $sdk */
        $sdk = $this->app->make(AgentPing::class);
        $this->assertSame(1, $sdk->status()['queue_size']);
    }

    public function test_event_and_finish_enqueue_separate_payloads(): void
    {
        Http::fake();
        $run = AgentPingFacade::run('triage');
        $run->event('log', ['message' => 'classifying']);
        $run->event('llm_call', [
            'provider' => 'anthropic',
            'model' => 'claude-opus-4-5',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'latency_ms' => 1234,
        ]);
        $run->finish('success', output: ['result' => 'ok'], scores: ['confidence' => 0.92]);

        /** @var AgentPing $sdk */
        $sdk = $this->app->make(AgentPing::class);
        $this->assertSame(4, $sdk->status()['queue_size']);
    }

    public function test_finish_is_idempotent(): void
    {
        Http::fake();
        $run = AgentPingFacade::run('a');
        $run->finish('success');
        $run->finish('success');

        /** @var AgentPing $sdk */
        $sdk = $this->app->make(AgentPing::class);
        $this->assertSame(2, $sdk->status()['queue_size']);
    }

    public function test_flush_sends_batched_post_with_expected_shape(): void
    {
        Http::fake([
            '*' => Http::response(['accepted' => 1], 202),
        ]);

        $run = AgentPingFacade::run('triage', customerId: 'acme');
        $run->event('llm_call', [
            'provider' => 'anthropic',
            'model' => 'claude-opus-4-5',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'latency_ms' => 1234,
        ]);
        $run->finish('success');

        AgentPingFacade::flush(5.0);

        Http::assertSentCount(3);
        Http::assertSent(function ($request) use ($run) {
            return str_ends_with($request->url(), '/v1/runs')
                && $request['id'] === $run->id()
                && $request['agent'] === 'triage'
                && $request['customer_id'] === 'acme';
        });
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/events')
                && is_array($request['events'])
                && $request['events'][0]['type'] === 'llm_call'
                && $request['events'][0]['data']['provider'] === 'anthropic';
        });
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/finish')
                && $request['status'] === 'success';
        });

        $this->assertSame(0, AgentPingFacade::status()['queue_size']);
        $this->assertNotNull(AgentPingFacade::status()['last_flush_at']);
    }

    public function test_status_shape(): void
    {
        $status = AgentPingFacade::status();
        $this->assertArrayHasKey('queue_size', $status);
        $this->assertArrayHasKey('dropped_count', $status);
        $this->assertArrayHasKey('last_flush_at', $status);
        $this->assertArrayHasKey('last_error', $status);
    }
}
