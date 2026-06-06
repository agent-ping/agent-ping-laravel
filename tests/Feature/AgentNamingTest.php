<?php

namespace AgentPing\Laravel\Tests\Feature;

use AgentPing\Laravel\AgentPing;
use AgentPing\Laravel\Facades\AgentPing as AgentPingFacade;
use AgentPing\Laravel\Listeners\HandleAgentPrompted;
use AgentPing\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class AgentNamingTest extends TestCase
{
    /**
     * Build an AgentPrompted-shaped event with the given prompt object.
     */
    private function aiEvent(string $invocationId, object $prompt): object
    {
        return (object) [
            'invocationId' => $invocationId,
            'prompt' => $prompt,
            'response' => (object) [
                'usage' => (object) [
                    'promptTokens' => 100,
                    'completionTokens' => 20,
                    'cacheReadInputTokens' => 0,
                    'cacheWriteInputTokens' => 0,
                    'reasoningTokens' => 0,
                ],
                'meta' => (object) ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5'],
            ],
        ];
    }

    /**
     * Regression: the ingest edge requires `external_id` on each event and
     * rejects unknown fields. The SDK must not send `id`.
     */
    public function test_event_payload_uses_external_id_not_id(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        $run = AgentPingFacade::run('regression');
        $run->event('llm_call', ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5']);
        AgentPingFacade::flush(5.0);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/events')) {
                return false;
            }
            $evt = $request['events'][0] ?? [];

            return isset($evt['external_id'])
                && str_starts_with((string) $evt['external_id'], 'evt_')
                && ! array_key_exists('id', $evt);
        });
    }

    /**
     * Anonymous agent() with no run wrapping aggregates under the configured
     * default agent name (here the package default, 'ai-agent').
     */
    public function test_anonymous_agent_falls_back_to_default_agent_name(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        $this->app->make(HandleAgentPrompted::class)
            ->handle($this->aiEvent('inv-anon-1', new \stdClass));

        AgentPingFacade::flush(5.0);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/runs')
            && ($r['agent'] ?? null) === 'ai-agent');
    }

    /**
     * AgentPing::agent(name, callback) names the run, attaches auto-captured
     * llm_calls to it, finishes it, and restores the previous current run.
     */
    public function test_scoped_agent_helper_names_run_and_attaches_llm_call(): void
    {
        Http::fake(['*' => Http::response([], 202)]);

        /** @var AgentPing $sdk */
        $sdk = $this->app->make(AgentPing::class);

        $result = $sdk->agent('hook-generator', function () {
            $this->app->make(HandleAgentPrompted::class)
                ->handle($this->aiEvent('inv-scoped-1', new \stdClass));

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertNull($sdk->currentRun(), 'current run should be restored after the scope');

        AgentPingFacade::flush(5.0);

        // run created under the scoped name
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/runs')
            && ($r['agent'] ?? null) === 'hook-generator');
        // the auto-captured llm_call attached to the scoped run (no 'ai-agent' synthetic run)
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/v1/runs')
            && ($r['agent'] ?? null) === 'ai-agent');
        // and the scope finished the run
        Http::assertSent(fn ($r) => str_contains($r->url(), '/finish')
            && ($r['status'] ?? null) === 'success');
    }
}
