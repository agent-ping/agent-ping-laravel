<?php

namespace AgentPing\Laravel\Queue;

use AgentPing\Laravel\Client\HttpClient;
use AgentPing\Laravel\Support\WarnOnce;
use Carbon\Carbon;

class FlushWorker
{
    public const MAX_ATTEMPTS = 5;

    private ?Carbon $lastFlushAt = null;

    private ?string $lastError = null;

    private float $backoffUntil = 0.0;

    private float $backoffSecs = 1.0;

    public function __construct(
        private readonly BoundedQueue $queue,
        private readonly HttpClient $client,
        private readonly WarnOnce $warner,
        private readonly int $batchSize = 50,
    ) {}

    public function flush(float $timeoutSeconds = 5.0): int
    {
        $deadline = microtime(true) + max(0.0, $timeoutSeconds);
        $sent = 0;
        $first = true;
        while (microtime(true) < $deadline) {
            // Honour backoff between retries (e.g. an event waiting for its
            // run-start to be persisted) but never let a stale backoff block
            // the first attempt of an explicit flush.
            if (! $first && microtime(true) < $this->backoffUntil) {
                $wait = min($this->backoffUntil, $deadline) - microtime(true);
                if ($wait > 0) {
                    usleep((int) ($wait * 1_000_000));
                }
            }
            $first = false;

            $items = $this->queue->drain($this->batchSize);
            if ($items === []) {
                break;
            }
            $sent += $this->send($items, overrideBackoff: false);
        }

        return $sent;
    }

    public function tick(): int
    {
        if (microtime(true) < $this->backoffUntil) {
            return 0;
        }
        $items = $this->queue->drain($this->batchSize);
        if ($items === []) {
            return 0;
        }

        return $this->send($items, overrideBackoff: false);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function send(array $items, bool $overrideBackoff): int
    {
        $failed = [];
        $dropped = 0;
        $accepted = 0;

        foreach ($items as $item) {
            $path = (string) ($item['path'] ?? '');
            /** @var array<string, mixed> $body */
            $body = (array) ($item['body'] ?? []);
            $attempts = (int) ($item['attempts'] ?? 0);

            $resp = $this->client->post($path, $body);
            $status = $resp['status'];

            if ($resp['error'] !== null) {
                $this->lastError = $resp['error'];
                $attempts++;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $dropped++;
                } else {
                    $item['attempts'] = $attempts;
                    $failed[] = $item;
                }

                continue;
            }

            if ($status === 200 || $status === 202) {
                $accepted++;

                continue;
            }

            if ($status === 409) {
                // Idempotent duplicate, treat as accepted.
                $accepted++;

                continue;
            }

            if ($status === 429) {
                if ($resp['retry_after'] !== null && ! $overrideBackoff) {
                    $this->backoffUntil = microtime(true) + (float) $resp['retry_after'];
                }
                $attempts++;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $dropped++;
                } else {
                    $item['attempts'] = $attempts;
                    $failed[] = $item;
                }

                continue;
            }

            if ($status >= 500 && $status < 600) {
                $this->lastError = 'server_status_' . $status;
                $attempts++;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $dropped++;
                } else {
                    $item['attempts'] = $attempts;
                    $failed[] = $item;
                }

                continue;
            }

            if ($status === 404) {
                // The run this event/finish targets may not be persisted yet:
                // run-start is published to the bus and consumed asynchronously,
                // while the edge resolves the run synchronously. Retry with
                // backoff so the write lands once run-start has been processed,
                // instead of dropping it. A genuinely missing run exhausts
                // attempts and drops, like any other failure.
                $this->lastError = 'run_not_ready_404';
                $attempts++;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $dropped++;
                } else {
                    $item['attempts'] = $attempts;
                    $failed[] = $item;
                }

                continue;
            }

            if (in_array($status, [400, 401, 403, 422], true)) {
                $this->lastError = 'client_status_' . $status;

                continue;
            }

            $this->lastError = 'status_' . $status;
            $attempts++;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $dropped++;
            } else {
                $item['attempts'] = $attempts;
                $failed[] = $item;
            }
        }

        $this->lastFlushAt = Carbon::now();

        if ($failed !== []) {
            if (! $overrideBackoff) {
                $this->backoffUntil = max($this->backoffUntil, microtime(true) + $this->backoffSecs);
                $this->backoffSecs = min($this->backoffSecs * 2, 30.0);
            }
            $this->queue->pushFront($failed);
        } else {
            $this->backoffSecs = 1.0;
        }

        if ($dropped > 0) {
            $this->queue->addDropped($dropped);
            $this->warner->warn(
                'network_error',
                'telemetry batch exhausted retries; events dropped.'
            );
        }

        return $accepted;
    }

    public function lastFlushAt(): ?Carbon
    {
        return $this->lastFlushAt;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}
