<?php

namespace AgentPing\Laravel\Tests\Unit;

use AgentPing\Laravel\Queue\BoundedQueue;
use PHPUnit\Framework\TestCase;

class BoundedQueueDropOldestTest extends TestCase
{
    public function test_push_below_capacity_does_not_drop(): void
    {
        $q = new BoundedQueue(3);
        $q->push(['n' => 1]);
        $q->push(['n' => 2]);
        $this->assertSame(2, $q->size());
        $this->assertSame(0, $q->droppedCount());
    }

    public function test_overflow_drops_oldest(): void
    {
        $q = new BoundedQueue(2);
        $q->push(['n' => 1]);
        $q->push(['n' => 2]);
        $q->push(['n' => 3]);

        $this->assertSame(2, $q->size());
        $this->assertSame(1, $q->droppedCount());

        $drained = $q->drain(10);
        $this->assertSame([['n' => 2], ['n' => 3]], $drained);
    }

    public function test_pushing_1001_into_1000_keeps_latest_1000_and_drops_one(): void
    {
        $q = new BoundedQueue(1000);
        for ($i = 1; $i <= 1001; $i++) {
            $q->push(['n' => $i]);
        }
        $this->assertSame(1000, $q->size());
        $this->assertSame(1, $q->droppedCount());

        $drained = $q->drain(1000);
        $this->assertSame(2, $drained[0]['n']);
        $this->assertSame(1001, $drained[999]['n']);
    }

    public function test_push_front_preserves_order_for_retry(): void
    {
        $q = new BoundedQueue(10);
        $q->push(['n' => 'fresh1']);
        $q->push(['n' => 'fresh2']);
        $q->pushFront([['n' => 'retry1'], ['n' => 'retry2']]);

        $drained = $q->drain(10);
        $this->assertSame('retry1', $drained[0]['n']);
        $this->assertSame('retry2', $drained[1]['n']);
        $this->assertSame('fresh1', $drained[2]['n']);
        $this->assertSame('fresh2', $drained[3]['n']);
    }
}
