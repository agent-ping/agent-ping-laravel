<?php

namespace AgentPing\Laravel\Tests\Feature;

use AgentPing\Laravel\AgentPing;
use AgentPing\Laravel\Facades\AgentPing as AgentPingFacade;
use AgentPing\Laravel\Listeners\HandleAgentFailed;
use AgentPing\Laravel\Listeners\HandleAgentPrompted;
use AgentPing\Laravel\Listeners\HandleProviderFailedOver;
use AgentPing\Laravel\Listeners\HandleStepCompleted;
use AgentPing\Laravel\Listeners\HandleStepFailed;
use AgentPing\Laravel\Listeners\HandleToolInvoked;
use AgentPing\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * The listeners beyond AgentPrompted: failures, steps, tools and failover.
 * Events are stdClass stand-ins shaped like the laravel/ai 0.x classes.
 */
class AiSdkEventSurfaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([], 202)]);
    }

    /** @return array<int, array<string, mixed>> every event body sent, in order */
    private function sentEvents(): array
    {
        AgentPingFacade::flush(5.0);

        $events = [];
        foreach (Http::recorded(fn ($request) => str_contains($request->url(), '/events')) as [$request]) {
            foreach ($request['events'] ?? [] as $evt) {
                $events[] = $evt;
            }
        }

        return $events;
    }

    /** @return array<int, array<string, mixed>> every finish body sent */
    private function sentFinishes(): array
    {
        $finishes = [];
        foreach (Http::recorded(fn ($request) => str_ends_with($request->url(), '/finish')) as [$request]) {
            $finishes[] = $request->data();
        }

        return $finishes;
    }

    private function usage(int $in, int $out, int $cacheRead = 0, int $reasoning = 0): object
    {
        return (object) [
            'promptTokens' => $in,
            'completionTokens' => $out,
            'cacheReadInputTokens' => $cacheRead,
            'cacheWriteInputTokens' => 0,
            'reasoningTokens' => $reasoning,
        ];
    }

    private function stepResponse(int $in, int $out, string $finish = 'stop', array $toolCalls = []): object
    {
        return (object) [
            'text' => 'ok',
            'toolCalls' => $toolCalls,
            'finishReason' => (object) ['value' => $finish],
            'usage' => $this->usage($in, $out),
            'meta' => (object) ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
        ];
    }

    private function agent(): object
    {
        return new class
        {
            public function instructions(): string
            {
                return 'help';
            }
        };
    }

    public function test_agent_failed_records_error_and_finishes_synthetic_run_as_error(): void
    {
        $event = (object) [
            'invocationId' => 'inv-fail-1',
            'prompt' => (object) ['agent' => 'App\\Agents\\BillingAgent'],
            'exception' => new \RuntimeException('provider timeout'),
        ];

        $this->app->make(HandleAgentFailed::class)->handle($event);

        $events = $this->sentEvents();
        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]['type']);
        $this->assertSame('provider timeout', $events[0]['data']['error']);
        $this->assertSame(\RuntimeException::class, $events[0]['data']['exception']);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/runs')
            && ($r['agent'] ?? null) === 'billing_agent');

        $finishes = $this->sentFinishes();
        $this->assertCount(1, $finishes);
        $this->assertSame('error', $finishes[0]['status']);

        $this->assertNull($this->app->make(AgentPing::class)->currentRun());
    }

    public function test_agent_failed_inside_app_run_does_not_finish_it(): void
    {
        $run = AgentPingFacade::run('support');

        $this->app->make(HandleAgentFailed::class)->handle((object) [
            'invocationId' => 'inv-fail-2',
            'prompt' => new \stdClass,
            'exception' => new \RuntimeException('boom'),
        ]);

        $events = $this->sentEvents();
        $this->assertSame('error', $events[0]['type']);
        $this->assertSame([], $this->sentFinishes());
        $this->assertFalse($run->isFinished());
        $this->assertSame($run, $this->app->make(AgentPing::class)->currentRun());
    }

    public function test_steps_emit_one_llm_call_each_and_agent_prompted_does_not_price_again(): void
    {
        $agent = $this->agent();
        $provider = new class
        {
            public function name(): string
            {
                return 'anthropic';
            }
        };

        $step = fn (int $n, bool $final, object $response) => (object) [
            'invocationId' => 'inv-steps-1',
            'stepNumber' => $n,
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'claude-sonnet-4-5',
            'isFinalStep' => $final,
            'response' => $response,
            'time' => 812.4,
        ];

        $steps = $this->app->make(HandleStepCompleted::class);
        $steps->handle($step(1, false, $this->stepResponse(1000, 40, 'tool_calls', [(object) ['id' => 'c1', 'name' => 'lookup']])));
        $steps->handle($step(2, true, $this->stepResponse(1300, 200)));

        // The prompt-level event carries the summed usage of both steps.
        $this->app->make(HandleAgentPrompted::class)->handle((object) [
            'invocationId' => 'inv-steps-1',
            'prompt' => (object) ['agent' => $agent],
            'response' => (object) [
                'usage' => $this->usage(2300, 240),
                'meta' => (object) ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
            ],
        ]);

        $events = $this->sentEvents();
        $llmCalls = array_values(array_filter($events, fn ($e) => $e['type'] === 'llm_call'));
        $this->assertCount(2, $llmCalls, 'expected one llm_call per step and none for the prompt');

        $first = $llmCalls[0]['data'];
        $this->assertSame('anthropic', $first['provider']);
        $this->assertSame('claude-sonnet-4-5', $first['model']);
        $this->assertSame(1000, $first['input_tokens']);
        $this->assertSame(40, $first['output_tokens']);
        $this->assertSame(812, $first['latency_ms']);
        $this->assertSame(1, $first['step']);
        $this->assertFalse($first['final']);
        $this->assertSame('tool_calls', $first['finish_reason']);
        $this->assertSame(1, $first['tool_calls']);

        $second = $llmCalls[1]['data'];
        $this->assertSame(2, $second['step']);
        $this->assertTrue($second['final']);
        $this->assertArrayNotHasKey('tool_calls', $second);

        // One synthetic run, named after the anonymous class fallback, finished once.
        $runs = 0;
        Http::assertSent(function ($r) use (&$runs) {
            if (str_ends_with($r->url(), '/v1/runs')) {
                $runs++;
            }

            return true;
        });
        $this->assertSame(1, $runs);
        $finishes = $this->sentFinishes();
        $this->assertCount(1, $finishes);
        $this->assertSame('success', $finishes[0]['status']);
        $this->assertNull($this->app->make(AgentPing::class)->currentRun());
    }

    public function test_agent_prompted_without_steps_still_emits_single_llm_call(): void
    {
        $this->app->make(HandleAgentPrompted::class)->handle((object) [
            'invocationId' => 'inv-nosteps-1',
            'prompt' => (object) ['agent' => 'App\\Agents\\WriterAgent'],
            'response' => (object) [
                'usage' => $this->usage(10, 5),
                'meta' => (object) ['provider' => 'openai', 'model' => 'gpt-4o'],
            ],
        ], stream: true);

        $events = $this->sentEvents();
        $this->assertCount(1, $events);
        $this->assertSame('llm_call', $events[0]['type']);
        $this->assertTrue($events[0]['data']['stream']);
        $this->assertSame(10, $events[0]['data']['input_tokens']);
    }

    public function test_step_marker_is_consumed_so_the_next_invocation_is_priced(): void
    {
        $sdk = $this->app->make(AgentPing::class);
        $sdk->markInvocationSteps('inv-a');

        $this->assertTrue($sdk->takeInvocationSteps('inv-a'));
        $this->assertFalse($sdk->takeInvocationSteps('inv-a'));
        $this->assertFalse($sdk->takeInvocationSteps('inv-b'));
    }

    public function test_step_failed_emits_error_llm_call_without_tokens(): void
    {
        $this->app->make(HandleStepFailed::class)->handle((object) [
            'invocationId' => 'inv-stepfail-1',
            'stepNumber' => 1,
            'agent' => $this->agent(),
            'provider' => (object) [],
            'model' => 'gpt-4o',
            'isFinalStep' => false,
            'exception' => new \RuntimeException('429 rate limited'),
            'time' => 55.0,
        ]);

        $events = $this->sentEvents();
        $this->assertCount(1, $events);
        $data = $events[0]['data'];
        $this->assertSame('llm_call', $events[0]['type']);
        $this->assertSame('error', $data['status']);
        $this->assertSame('429 rate limited', $data['error']);
        $this->assertSame('gpt-4o', $data['model']);
        $this->assertSame('unknown', $data['provider']);
        $this->assertSame(55, $data['latency_ms']);
        $this->assertArrayNotHasKey('input_tokens', $data);
    }

    public function test_tool_invoked_emits_tool_call_with_payloads(): void
    {
        $tool = new class
        {
            public function name(): string
            {
                return 'lookup_order';
            }
        };

        $this->app->make(HandleToolInvoked::class)->handle((object) [
            'invocationId' => 'inv-tool-1',
            'toolInvocationId' => 'ti-1',
            'agent' => $this->agent(),
            'tool' => $tool,
            'arguments' => ['id' => 42],
            'result' => ['status' => 'shipped', 'eta' => 'tomorrow'],
            'time' => 12.6,
        ]);

        $events = $this->sentEvents();
        $this->assertCount(1, $events);
        $data = $events[0]['data'];
        $this->assertSame('tool_call', $events[0]['type']);
        $this->assertSame('lookup_order', $data['tool']);
        $this->assertSame('ti-1', $data['tool_invocation_id']);
        $this->assertSame('success', $data['status']);
        $this->assertSame(13, $data['latency_ms']);
        $this->assertSame('{"id":42}', $data['input']);
        $this->assertSame('{"status":"shipped","eta":"tomorrow"}', $data['output']);
    }

    public function test_tool_name_falls_back_to_class_basename(): void
    {
        $this->app->make(HandleToolInvoked::class)->handle((object) [
            'invocationId' => 'inv-tool-2',
            'toolInvocationId' => 'ti-2',
            'agent' => $this->agent(),
            'tool' => new \DateTimeImmutable,
            'arguments' => [],
            'result' => 'done',
            'time' => 1.0,
        ]);

        $events = $this->sentEvents();
        $this->assertSame('DateTimeImmutable', $events[0]['data']['tool']);
        $this->assertArrayNotHasKey('input', $events[0]['data']);
        $this->assertSame('done', $events[0]['data']['output']);
    }

    public function test_tool_payload_capture_can_be_disabled_and_is_truncated(): void
    {
        config()->set('agentping.capture_tool_payloads', false);

        $handler = $this->app->make(HandleToolInvoked::class);
        $handler->handle((object) [
            'invocationId' => 'inv-tool-3',
            'toolInvocationId' => 'ti-3',
            'agent' => $this->agent(),
            'tool' => new \DateTimeImmutable,
            'arguments' => ['secret' => 'x'],
            'result' => 'y',
            'time' => 1.0,
        ]);

        config()->set('agentping.capture_tool_payloads', true);
        config()->set('agentping.tool_payload_max_chars', 10);
        $handler->handle((object) [
            'invocationId' => 'inv-tool-3',
            'toolInvocationId' => 'ti-4',
            'agent' => $this->agent(),
            'tool' => new \DateTimeImmutable,
            'arguments' => [],
            'result' => str_repeat('a', 50),
            'time' => 1.0,
        ]);

        $events = $this->sentEvents();
        $this->assertCount(2, $events);
        $this->assertArrayNotHasKey('input', $events[0]['data']);
        $this->assertArrayNotHasKey('output', $events[0]['data']);
        $this->assertSame(str_repeat('a', 10) . '...', $events[1]['data']['output']);
    }

    public function test_tool_failed_emits_error_tool_call(): void
    {
        $this->app->make(HandleToolInvoked::class)->handle((object) [
            'invocationId' => 'inv-tool-5',
            'toolInvocationId' => 'ti-5',
            'agent' => $this->agent(),
            'tool' => new \DateTimeImmutable,
            'arguments' => ['id' => 1],
            'exception' => new \InvalidArgumentException('no such order'),
            'time' => 3.0,
        ], failed: true);

        $events = $this->sentEvents();
        $data = $events[0]['data'];
        $this->assertSame('tool_call', $events[0]['type']);
        $this->assertSame('error', $data['status']);
        $this->assertSame('no such order', $data['error']);
        $this->assertSame(\InvalidArgumentException::class, $data['exception']);
        $this->assertSame('{"id":1}', $data['input']);
        $this->assertArrayNotHasKey('output', $data);
    }

    public function test_tool_and_step_events_share_one_synthetic_run_per_invocation(): void
    {
        $agent = $this->agent();

        $this->app->make(HandleToolInvoked::class)->handle((object) [
            'invocationId' => 'inv-shared-1',
            'toolInvocationId' => 'ti-1',
            'agent' => $agent,
            'tool' => new \DateTimeImmutable,
            'arguments' => [],
            'result' => 'x',
            'time' => 1.0,
        ]);
        $this->app->make(HandleStepCompleted::class)->handle((object) [
            'invocationId' => 'inv-shared-1',
            'stepNumber' => 2,
            'agent' => $agent,
            'provider' => (object) [],
            'model' => 'gpt-4o',
            'isFinalStep' => true,
            'response' => $this->stepResponse(5, 5),
            'time' => 1.0,
        ]);

        AgentPingFacade::flush(5.0);

        $runIds = [];
        Http::assertSent(function ($r) use (&$runIds) {
            if (str_contains($r->url(), '/events')) {
                preg_match('#/v1/runs/([^/]+)/events#', $r->url(), $m);
                $runIds[] = $m[1];
            }

            return true;
        });
        $this->assertCount(2, $runIds);
        $this->assertSame($runIds[0], $runIds[1]);
    }

    public function test_provider_failed_over_emits_step_on_invocation_run(): void
    {
        $this->app->make(HandleProviderFailedOver::class)->handle((object) [
            'invocationId' => 'inv-failover-1',
            'agent' => $this->agent(),
            'provider' => new class
            {
                public function name(): string
                {
                    return 'OpenAI';
                }
            },
            'model' => 'gpt-4o',
            'exception' => new \RuntimeException('503 overloaded'),
        ]);

        $events = $this->sentEvents();
        $this->assertCount(1, $events);
        $data = $events[0]['data'];
        $this->assertSame('step', $events[0]['type']);
        $this->assertSame('provider_failover', $data['kind']);
        $this->assertSame('openai', $data['provider']);
        $this->assertSame('gpt-4o', $data['model']);
        $this->assertSame('503 overloaded', $data['reason']);
        $this->assertArrayNotHasKey('error', $data, 'a failover must not mark the run as errored');
    }

    public function test_provider_failed_over_without_invocation_or_run_is_ignored(): void
    {
        $this->app->make(HandleProviderFailedOver::class)->handle((object) [
            'provider' => (object) [],
            'model' => 'gpt-4o',
            'exception' => new \RuntimeException('503'),
        ]);

        $this->assertSame([], $this->sentEvents());
    }

    public function test_listeners_swallow_malformed_events(): void
    {
        foreach ([
            HandleAgentFailed::class,
            HandleStepCompleted::class,
            HandleStepFailed::class,
            HandleToolInvoked::class,
            HandleProviderFailedOver::class,
        ] as $listener) {
            $this->app->make($listener)->handle((object) ['invocationId' => 'inv-broken', 'response' => 'not-an-object', 'time' => 'soon']);
        }

        $this->assertTrue(true);
    }
}
