<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Closure;
use Qiick\Excubia\Contracts\HttpClientContract;

/**
 * Forwards HTTP calls and tells the CLI which URL was requested.
 *
 * The notice is the method and URL only, without URL credentials. Headers,
 * including a GitHub token, are not passed to the listener.
 */
final class NotifyingHttpClientService implements HttpClientContract
{
    public function __construct(
        private readonly HttpClientContract $httpClient,
        private readonly Closure $onRequest,
    ) {}

    public function getJson(string $url, array $queryParameters = [], array $headers = []): array
    {
        ($this->onRequest)('GET '.SymfonyHttpClientService::redactedUrl($url));

        return $this->httpClient->getJson($url, $queryParameters, $headers);
    }

    public function postJson(string $url, array $payload, array $headers = []): array
    {
        ($this->onRequest)('POST '.SymfonyHttpClientService::redactedUrl($url));

        return $this->httpClient->postJson($url, $payload, $headers);
    }
}
