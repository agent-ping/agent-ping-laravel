<?php

namespace AgentPing\Laravel\Tests\Feature;

use AgentPing\Laravel\AgentPing;
use AgentPing\Laravel\Facades\AgentPing as AgentPingFacade;
use AgentPing\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class HttpClientTest extends TestCase
{
    public function test_authorization_header_and_content_type_are_set(): void
    {
        Http::fake([
            '*' => Http::response([], 202),
        ]);

        AgentPingFacade::heartbeat('hb', status: 'ok');
        AgentPingFacade::flush(5.0);

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization');
            $ct = $request->header('Content-Type');
            $ua = $request->header('User-Agent');

            return is_array($auth) && $auth[0] === 'Bearer apk_eu_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
                && is_array($ct) && str_contains($ct[0], 'application/json')
                && is_array($ua) && str_starts_with($ua[0], 'agentping-laravel/');
        });
    }

    public function test_429_with_retry_after_keeps_item_for_retry(): void
    {
        Http::fakeSequence()
            ->push([], 429, ['Retry-After' => '0'])
            ->push([], 202);

        AgentPingFacade::heartbeat('hb', status: 'ok');

        AgentPingFacade::flush(5.0);

        /** @var AgentPing $sdk */
        $sdk = $this->app->make(AgentPing::class);
        $this->assertSame(0, $sdk->status()['queue_size']);
    }

    public function test_500_response_triggers_retry_path(): void
    {
        Http::fakeSequence()
            ->push([], 500)
            ->push([], 202);

        AgentPingFacade::heartbeat('hb', status: 'ok');

        AgentPingFacade::flush(5.0);

        /** @var AgentPing $sdk */
        $sdk = $this->app->make(AgentPing::class);
        $this->assertSame(0, $sdk->status()['queue_size']);
        $this->assertNotNull($sdk->status()['last_error']);
    }

    public function test_400_is_terminal_and_does_not_retry(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'bad'], 400),
        ]);

        AgentPingFacade::heartbeat('hb', status: 'ok');
        AgentPingFacade::flush(5.0);

        /** @var AgentPing $sdk */
        $sdk = $this->app->make(AgentPing::class);
        $this->assertSame(0, $sdk->status()['queue_size']);
        Http::assertSentCount(1);
    }
}
