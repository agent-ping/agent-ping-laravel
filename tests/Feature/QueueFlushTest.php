<?php

namespace AgentPing\Laravel\Tests\Feature;

use AgentPing\Laravel\AgentPingServiceProvider;
use AgentPing\Laravel\Facades\AgentPing as AgentPingFacade;
use AgentPing\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class QueueFlushTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // Enable the auto-flush lifecycle so the queue listeners register.
        $app['config']->set('agentping.auto_register_terminating', true);
    }

    /**
     * In a long-running worker, app->terminating does not fire per job, so the
     * SDK must flush on JobProcessed to ship telemetry emitted inside the job.
     */
    public function test_flushes_after_a_job_is_processed(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        // Telemetry emitted "inside a job" (enqueued, not yet flushed).
        AgentPingFacade::run('queued-work');

        // Simulate the worker finishing the job.
        $this->app['events']->dispatch(AgentPingServiceProvider::QUEUE_EVENT_PROCESSED);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/runs')
            && ($r['agent'] ?? null) === 'queued-work');
    }

    public function test_flushes_after_a_job_fails(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        AgentPingFacade::run('queued-work-failed');

        $this->app['events']->dispatch(AgentPingServiceProvider::QUEUE_EVENT_FAILED);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/runs')
            && ($r['agent'] ?? null) === 'queued-work-failed');
    }
}
