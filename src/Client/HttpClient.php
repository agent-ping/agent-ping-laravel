<?php

namespace AgentPing\Laravel\Client;

use AgentPing\Laravel\Support\WarnOnce;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

class HttpClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly WarnOnce $warner,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly float $timeout = 2.0,
        private readonly string $userAgent = 'agentping-laravel/0.1.0',
    ) {}

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, body: array<string, mixed>, retry_after: ?float, error: ?string}
     */
    public function post(string $path, array $body): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;

        try {
            /** @var Response $response */
            $response = $this->http
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'User-Agent' => $this->userAgent,
                ])
                ->timeout((int) max(1, ceil($this->timeout)))
                ->connectTimeout((int) max(1, ceil($this->timeout)))
                ->post($url, $body);
        } catch (ConnectionException $e) {
            $this->warner->warn(
                'network_error',
                'network error contacting AgentPing (' . class_basename($e) . '); will retry.'
            );

            return [
                'status' => 0,
                'body' => [],
                'retry_after' => null,
                'error' => 'network_error: ' . $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            $this->warner->warn(
                'network_error',
                'unexpected transport error (' . class_basename($e) . '); will retry.'
            );

            return [
                'status' => 0,
                'body' => [],
                'retry_after' => null,
                'error' => 'transport_error: ' . $e->getMessage(),
            ];
        }

        $status = $response->status();
        $retryAfter = null;
        $ra = $response->header('Retry-After');
        if ($ra !== null && $ra !== '') {
            $raVal = filter_var($ra, FILTER_VALIDATE_FLOAT);
            if ($raVal !== false) {
                $retryAfter = (float) $raVal;
            }
        }

        if ($status === 401 || $status === 403) {
            $this->warner->warn(
                'auth_error',
                'API key rejected by AgentPing; telemetry will be dropped.'
            );
        } elseif ($status >= 500 && $status < 600) {
            $this->warner->warn(
                'server_error',
                'AgentPing returned HTTP ' . $status . '; will retry.'
            );
        }

        $parsed = [];
        try {
            $parsed = $response->json() ?? [];
            if (! is_array($parsed)) {
                $parsed = [];
            }
        } catch (\Throwable) {
            $parsed = [];
        }

        return [
            'status' => $status,
            'body' => $parsed,
            'retry_after' => $retryAfter,
            'error' => null,
        ];
    }
}
