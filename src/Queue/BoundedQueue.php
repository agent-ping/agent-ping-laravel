<?php

namespace AgentPing\Laravel\Queue;

class BoundedQueue
{
    /** @var array<int, array<string, mixed>> */
    private array $items = [];

    private int $droppedCount = 0;

    public function __construct(private readonly int $maxSize = 1000) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public function push(array $item): void
    {
        if (count($this->items) >= $this->maxSize) {
            array_shift($this->items);
            $this->droppedCount++;
        }
        $this->items[] = $item;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function drain(int $max): array
    {
        if ($max <= 0 || $this->items === []) {
            return [];
        }
        $out = array_splice($this->items, 0, $max);

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function pushFront(array $items): void
    {
        foreach (array_reverse($items) as $item) {
            if (count($this->items) >= $this->maxSize) {
                array_pop($this->items);
                $this->droppedCount++;
            }
            array_unshift($this->items, $item);
        }
    }

    public function size(): int
    {
        return count($this->items);
    }

    public function droppedCount(): int
    {
        return $this->droppedCount;
    }

    public function addDropped(int $n): void
    {
        $this->droppedCount += $n;
    }

    public function clear(): void
    {
        $this->items = [];
    }
}
