<?php

namespace AgentPing\Laravel\Tests\Feature;

use AgentPing\Laravel\AgentPing;
use AgentPing\Laravel\Queue\BoundedQueue;
use AgentPing\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class BoundedQueueTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('agentping.queue_size', 5);
    }

    public function test_queue_size_config_applied_and_drops_oldest_when_full(): void
    {
        Http::fake();

        /** @var AgentPing $sdk */
        $sdk = $this->app->make(AgentPing::class);
        /** @var BoundedQueue $queue */
        $queue = $this->app->make(BoundedQueue::class);

        for ($i = 0; $i < 7; $i++) {
            $sdk->heartbeat('hb-' . $i, status: 'ok');
        }

        $this->assertSame(5, $queue->size());
        $this->assertSame(2, $queue->droppedCount());
        $status = $sdk->status();
        $this->assertSame(5, $status['queue_size']);
        $this->assertSame(2, $status['dropped_count']);
    }
}
