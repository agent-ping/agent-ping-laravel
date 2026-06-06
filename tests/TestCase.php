<?php

namespace AgentPing\Laravel\Tests;

use AgentPing\Laravel\AgentPingServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [AgentPingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('agentping.api_key', 'apk_eu_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $app['config']->set('agentping.base_url', 'https://api.agentping.test');
        $app['config']->set('agentping.queue_size', 1000);
        $app['config']->set('agentping.batch_size', 50);
        $app['config']->set('agentping.request_timeout', 2.0);
        $app['config']->set('agentping.listen_to_ai_sdk', true);
        $app['config']->set('agentping.auto_register_terminating', false);
    }
}
