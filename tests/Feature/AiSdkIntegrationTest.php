<?php

namespace AgentPing\Laravel\Tests\Feature;

use AgentPing\Laravel\AgentPing;
use AgentPing\Laravel\Facades\AgentPing as AgentPingFacade;
use AgentPing\Laravel\Listeners\HandleAgentPrompted;
use AgentPing\Laravel\Listeners\HandleEmbeddingsGenerated;
use AgentPing\Laravel\Listeners\RecordPromptStart;
use AgentPing\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class AiSdkIntegrationTest extends TestCase
{
    public function test_agent_prompted_emits_llm_call_event_with_mapped_payload(): void
    {
        Http::fake([
            '*' => Http::response([], 202),
        ]);

        $run = AgentPingFacade::run('support-triage');

        $usage = (object) [
            'promptTokens' => 1200,
            'completionTokens' => 480,
            'cacheReadInputTokens' => 300,
            'cacheWriteInputTokens' => 0,
            'reasoningTokens' => 200,
        ];
        $meta = (object) [
            'provider' => 'ANTHROPIC',
            'model' => 'claude-opus-4-5',
        ];
        $response = (object) [
            'usage' => $usage,
            'meta' => $meta,
            'text' => 'hello',
        ];
        $prompt = new \stdClass;
        $prompt->agent = 'App\\Agents\\SupportTriageAgent';

        $invocationId = 'inv-12345';

        /** @var RecordPromptStart $start */
        $start = $this->app->make(RecordPromptStart::class);
        $promptingEvent = (object) ['invocationId' => $invocationId];
        $start->handle($promptingEvent);

        usleep(10_000);

        $event = (object) [
            'invocationId' => $invocationId,
            'prompt' => $prompt,
            'response' => $response,
        ];

        /** @var HandleAgentPrompted $handler */
        $handler = $this->app->make(HandleAgentPrompted::class);
        $handler->handle($event);

        $run->finish('success');

        AgentPingFacade::flush(5.0);

        $sawLlmCall = false;
        Http::assertSent(function ($request) use (&$sawLlmCall) {
            if (! str_contains($request->url(), '/events')) {
                return false;
            }
            $events = $request['events'] ?? null;
            if (! is_array($events) || $events === []) {
                return false;
            }
            $evt = $events[0];
            if (($evt['type'] ?? null) !== 'llm_call') {
                return false;
            }
            $data = $evt['data'];
            $okBase = $data['provider'] === 'anthropic'
                && $data['model'] === 'claude-opus-4-5'
                && $data['input_tokens'] === 1200
                && $data['output_tokens'] === 480
                && ($data['cached_input_tokens'] ?? null) === 300
                && ! array_key_exists('cache_creation_input_tokens', $data)
                && ($data['reasoning_tokens'] ?? null) === 200
                && isset($data['latency_ms'])
                && ! array_key_exists('cost_usd', $data);
            if ($okBase) {
                $sawLlmCall = true;
            }

            return $okBase;
        });
        $this->assertTrue($sawLlmCall, 'Did not see an llm_call event with mapped payload.');
    }

    public function test_agent_prompted_without_current_run_creates_synthetic_run(): void
    {
        Http::fake([
            '*' => Http::response([], 202),
        ]);

        $event = (object) [
            'invocationId' => 'inv-synthetic-1',
            'prompt' => (object) ['agent' => 'App\\Agents\\ReportWriterAgent'],
            'response' => (object) [
                'usage' => (object) [
                    'promptTokens' => 10,
                    'completionTokens' => 5,
                    'cacheReadInputTokens' => 0,
                    'cacheWriteInputTokens' => 0,
                    'reasoningTokens' => 0,
                ],
                'meta' => (object) ['provider' => 'openai', 'model' => 'gpt-4o'],
            ],
        ];

        /** @var HandleAgentPrompted $handler */
        $handler = $this->app->make(HandleAgentPrompted::class);
        $handler->handle($event);

        AgentPingFacade::flush(5.0);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/runs')
            && $r['agent'] === 'report_writer_agent'
            && ($r['metadata']['invocation_id'] ?? null) === 'inv-synthetic-1'
        );

        Http::assertSent(fn ($r) => str_contains($r->url(), '/events')
            && ($r['events'][0]['data']['provider'] ?? null) === 'openai'
        );

        Http::assertSent(fn ($r) => str_contains($r->url(), '/finish')
            && ($r['status'] ?? null) === 'success'
        );

        /** @var AgentPing $sdk */
        $sdk = $this->app->make(AgentPing::class);
        $this->assertNull($sdk->currentRun());
    }

    public function test_embeddings_generated_emits_kind_embedding(): void
    {
        Http::fake([
            '*' => Http::response([], 202),
        ]);

        $event = (object) [
            'invocationId' => 'inv-emb-1',
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'prompt' => new \stdClass,
            'response' => (object) [
                'usage' => (object) [
                    'promptTokens' => 12,
                    'completionTokens' => 0,
                    'cacheReadInputTokens' => 0,
                    'cacheWriteInputTokens' => 0,
                    'reasoningTokens' => 0,
                ],
            ],
        ];

        /** @var HandleEmbeddingsGenerated $handler */
        $handler = $this->app->make(HandleEmbeddingsGenerated::class);
        $handler->handle($event);

        AgentPingFacade::flush(5.0);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/events')) {
                return false;
            }
            $data = $request['events'][0]['data'] ?? [];

            return ($data['kind'] ?? null) === 'embedding'
                && $data['input_tokens'] === 12
                && $data['output_tokens'] === 0
                && $data['provider'] === 'openai';
        });
    }

    public function test_listener_swallows_exceptions(): void
    {
        Http::fake();

        $brokenEvent = (object) [
            'invocationId' => 'inv-broken',
            'prompt' => null,
            'response' => null,
        ];

        /** @var HandleAgentPrompted $handler */
        $handler = $this->app->make(HandleAgentPrompted::class);
        $handler->handle($brokenEvent);

        $this->assertTrue(true);
    }
}
