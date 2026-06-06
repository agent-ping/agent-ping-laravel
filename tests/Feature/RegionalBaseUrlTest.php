<?php

namespace AgentPing\Laravel\Tests\Feature;

use AgentPing\Laravel\AgentPingServiceProvider;
use AgentPing\Laravel\Client\HttpClient;
use Orchestra\Testbench\TestCase as BaseTestCase;
use ReflectionProperty;

/**
 * Region-aware default base URL contract.
 *
 * When `agentping.base_url` is left blank in config, the service provider
 * derives the host from the API key's region segment (spec §4.1, §2.9).
 * apk_eu_* -> https://eu.ingest.agentping.io
 * apk_us_* -> https://us.ingest.agentping.io
 *
 * Pinned here so we do not regress to the retired shared api.agentping.io
 * host.
 */
class RegionalBaseUrlTest extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [AgentPingServiceProvider::class];
    }

    private function bootWith(array $config): void
    {
        $this->refreshApplication();
        foreach ($config as $key => $value) {
            $this->app['config']->set("agentping.{$key}", $value);
        }
    }

    private function clientBaseUrl(): string
    {
        $client = $this->app->make(HttpClient::class);
        $ref = new ReflectionProperty($client, 'baseUrl');

        return $ref->getValue($client);
    }

    public function test_eu_key_with_blank_base_url_picks_eu_ingest_host(): void
    {
        $this->bootWith([
            'api_key' => 'apk_eu_'.str_repeat('a', 32),
            'base_url' => null,
        ]);

        $this->assertSame('https://eu.ingest.agentping.io', $this->clientBaseUrl());
    }

    public function test_us_key_with_blank_base_url_picks_us_ingest_host(): void
    {
        $this->bootWith([
            'api_key' => 'apk_us_'.str_repeat('f', 32),
            'base_url' => null,
        ]);

        $this->assertSame('https://us.ingest.agentping.io', $this->clientBaseUrl());
    }

    public function test_explicit_base_url_overrides_region_default(): void
    {
        $this->bootWith([
            'api_key' => 'apk_us_'.str_repeat('f', 32),
            'base_url' => 'https://api.test',
        ]);

        $this->assertSame('https://api.test', $this->clientBaseUrl());
    }

    public function test_missing_key_falls_back_to_eu_host(): void
    {
        $this->bootWith([
            'api_key' => null,
            'base_url' => null,
        ]);

        $this->assertSame('https://eu.ingest.agentping.io', $this->clientBaseUrl());
    }
}
