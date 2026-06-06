<?php

namespace AgentPing\Laravel;

use AgentPing\Laravel\Client\HttpClient;
use AgentPing\Laravel\Console\FlushCommand;
use AgentPing\Laravel\Listeners\HandleAgentPrompted;
use AgentPing\Laravel\Listeners\HandleEmbeddingsGenerated;
use AgentPing\Laravel\Listeners\RecordPromptStart;
use AgentPing\Laravel\Queue\BoundedQueue;
use AgentPing\Laravel\Queue\FlushWorker;
use AgentPing\Laravel\Support\Ids;
use AgentPing\Laravel\Support\WarnOnce;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class AgentPingServiceProvider extends ServiceProvider
{
    public const AI_EVENT_PROMPTED = 'Laravel\\Ai\\Events\\AgentPrompted';

    public const AI_EVENT_STREAMED = 'Laravel\\Ai\\Events\\AgentStreamed';

    public const AI_EVENT_EMBEDDINGS = 'Laravel\\Ai\\Events\\EmbeddingsGenerated';

    public const AI_EVENT_PROMPTING = 'Laravel\\Ai\\Events\\PromptingAgent';

    public const QUEUE_EVENT_PROCESSED = 'Illuminate\\Queue\\Events\\JobProcessed';

    public const QUEUE_EVENT_FAILED = 'Illuminate\\Queue\\Events\\JobFailed';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/agentping.php', 'agentping');

        $this->app->singleton(WarnOnce::class, fn () => new WarnOnce);

        $this->app->singleton(BoundedQueue::class, function (Application $app) {
            $size = (int) $app['config']->get('agentping.queue_size', 1000);

            return new BoundedQueue(maxSize: $size);
        });

        $this->app->singleton(HttpClient::class, function (Application $app) {
            $config = $app['config']->get('agentping');
            $apiKey = (string) ($config['api_key'] ?? '');
            $region = Ids::extractRegion($apiKey);
            $baseUrl = (string) ($config['base_url'] ?? '');
            if ($baseUrl === '') {
                $baseUrl = "https://{$region}.ingest.agentping.io";
            }

            return new HttpClient(
                http: $app->make(HttpFactory::class),
                warner: $app->make(WarnOnce::class),
                apiKey: $apiKey,
                baseUrl: $baseUrl,
                timeout: (float) ($config['request_timeout'] ?? 2.0),
                userAgent: (string) ($config['user_agent'] ?? 'agentping-laravel/0.1.0'),
            );
        });

        $this->app->singleton(FlushWorker::class, function (Application $app) {
            $config = $app['config']->get('agentping');

            return new FlushWorker(
                queue: $app->make(BoundedQueue::class),
                client: $app->make(HttpClient::class),
                warner: $app->make(WarnOnce::class),
                batchSize: (int) ($config['batch_size'] ?? 50),
            );
        });

        $this->app->singleton(AgentPing::class, function (Application $app) {
            $config = $app['config']->get('agentping');

            return new AgentPing(
                queue: $app->make(BoundedQueue::class),
                worker: $app->make(FlushWorker::class),
                client: $app->make(HttpClient::class),
                warner: $app->make(WarnOnce::class),
                apiKey: $config['api_key'] ?? null,
                defaultAgent: (string) ($config['default_agent'] ?? 'ai-agent'),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/agentping.php' => $this->configPath('agentping.php'),
        ], 'agentping-config');

        if ($this->app->runningInConsole()) {
            $this->commands([FlushCommand::class]);
        }

        $config = $this->app['config']->get('agentping');

        if ((bool) ($config['listen_to_ai_sdk'] ?? true)) {
            $this->registerAiListeners();
        }

        if ((bool) ($config['auto_register_terminating'] ?? true)) {
            $this->registerTerminatingFlush((float) ($config['terminating_flush_timeout'] ?? 10.0));
            $this->registerQueueFlush((float) ($config['terminating_flush_timeout'] ?? 10.0));
        }
    }

    private function registerAiListeners(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make(Dispatcher::class);

        if (class_exists(self::AI_EVENT_PROMPTING)) {
            $events->listen(self::AI_EVENT_PROMPTING, function (object $event): void {
                $this->app->make(RecordPromptStart::class)->handle($event);
            });
        }

        if (class_exists(self::AI_EVENT_PROMPTED)) {
            $events->listen(self::AI_EVENT_PROMPTED, function (object $event): void {
                $this->app->make(HandleAgentPrompted::class)->handle($event);
            });
        }

        if (class_exists(self::AI_EVENT_STREAMED)) {
            $events->listen(self::AI_EVENT_STREAMED, function (object $event): void {
                $this->app->make(HandleAgentPrompted::class)->handle($event);
            });
        }

        if (class_exists(self::AI_EVENT_EMBEDDINGS)) {
            $events->listen(self::AI_EVENT_EMBEDDINGS, function (object $event): void {
                $this->app->make(HandleEmbeddingsGenerated::class)->handle($event);
            });
        }
    }

    private function registerTerminatingFlush(float $timeout): void
    {
        $app = $this->app;
        $app->terminating(function () use ($app, $timeout): void {
            try {
                $sdk = $app->make(AgentPing::class);
                $sdk->flush($timeout);
                $sdk->resetScope();
            } catch (\Throwable) {
                // Never crash user code on shutdown.
            }
        });
    }

    /**
     * Flush after each queued job. In a long-running worker, app->terminating
     * only fires when the worker shuts down, so without this, telemetry emitted
     * inside a job would sit in memory until the worker stops (or be lost if it
     * is killed). JobProcessed/JobFailed give us a reliable per-job boundary.
     */
    private function registerQueueFlush(float $timeout): void
    {
        $app = $this->app;
        /** @var Dispatcher $events */
        $events = $app->make(Dispatcher::class);

        $flush = function () use ($app, $timeout): void {
            try {
                $sdk = $app->make(AgentPing::class);
                $sdk->flush($timeout);
                $sdk->resetScope();
            } catch (\Throwable) {
                // Never crash the worker.
            }
        };

        $events->listen(self::QUEUE_EVENT_PROCESSED, $flush);
        $events->listen(self::QUEUE_EVENT_FAILED, $flush);
    }

    private function configPath(string $file): string
    {
        if (function_exists('config_path')) {
            return config_path($file);
        }

        return $this->app->basePath('config/' . $file);
    }
}
