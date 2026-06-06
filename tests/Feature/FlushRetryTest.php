<?php

namespace AgentPing\Laravel\Tests\Feature;

use AgentPing\Laravel\Facades\AgentPing as AgentPingFacade;
use AgentPing\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class FlushRetryTest extends TestCase
{
    /**
     * An event whose run-start has not been persisted yet gets a 404 from the
     * edge. That is a transient eventual-consistency condition, not a client
     * error, so the SDK must retry it (with backoff) rather than drop it.
     */
    public function test_event_404_is_retried_until_run_is_ready(): void
    {
        // run-start -> 202; first event attempt -> 404 (run not persisted yet),
        // second attempt -> 202.
        Http::fake([
            '*/events' => Http::sequence()->push([], 404)->push([], 202),
            '*' => Http::response([], 202),
        ]);

        $run = AgentPingFacade::run('retry-test');
        $run->event('llm_call', ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5']);

        $accepted = AgentPingFacade::flush(8.0);

        $eventHits = 0;
        Http::assertSent(function ($request) use (&$eventHits) {
            if (str_contains($request->url(), '/events')) {
                $eventHits++;
            }

            return true;
        });

        $this->assertGreaterThanOrEqual(2, $eventHits, 'event should be retried after a 404');
        $this->assertSame(2, $accepted, 'run-start and the retried event should both be accepted');
    }

    /**
     * A real client error (422) is permanent and must not be retried.
     */
    public function test_422_is_dropped_not_retried(): void
    {
        Http::fake([
            '*/events' => Http::response([], 422),
            '*' => Http::response([], 202),
        ]);

        $run = AgentPingFacade::run('bad-payload');
        $run->event('llm_call', ['provider' => 'anthropic']);

        AgentPingFacade::flush(3.0);

        $eventHits = 0;
        Http::assertSent(function ($request) use (&$eventHits) {
            if (str_contains($request->url(), '/events')) {
                $eventHits++;
            }

            return true;
        });

        $this->assertSame(1, $eventHits, '422 is permanent and must not be retried');
    }
}
