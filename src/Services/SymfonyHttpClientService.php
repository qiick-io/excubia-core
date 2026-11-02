<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Symfony HTTP client used outside tests.
 *
 * Idle timeout is 5 seconds and the whole request may take 15 seconds.
 * Two retries use backoff (200 ms, then 400 ms). Symfony already reads
 * HTTPS_PROXY. ponytail: retry count is fixed; raise it here if a source
 * needs more than two attempts.
 */
final class SymfonyHttpClientService implements HttpClientContract
{
    private readonly HttpClientInterface $httpClient;

    public function __construct(?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new RetryableHttpClient(
            HttpClient::create(self::defaultOptions()),
            new GenericRetryStrategy(delayMs: 200, multiplier: 2.0, maxDelayMs: 1000, jitter: 0.0),
            2,
        );
    }

    /**
     * @return array{timeout: float, max_duration: float, headers: array<string, string>}
     */
    public static function defaultOptions(): array
    {
        return [
            'timeout' => 5.0,
            'max_duration' => 15.0,
            'headers' => [
                'User-Agent' => 'qiick-excubia',
                'Accept' => 'application/json',
            ],
        ];
    }

    /**
     * HTTPS, or plain HTTP to a loopback host so `excubia push` can reach
     * an `excubia-app` running on the same machine.
     */
    public static function isSecureUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($scheme) || ! is_string($host) || $host === '') {
            return false;
        }

        if (strtolower($scheme) === 'https') {
            return true;
        }

        return strtolower($scheme) === 'http' && in_array(strtolower($host), ['localhost', '127.0.0.1', '[::1]'], true);
    }

    public function getJson(string $url, array $queryParameters = [], array $headers = []): array
    {
        $target = $url;

        if ($queryParameters !== []) {
            $target .= (str_contains($url, '?') ? '&' : '?').http_build_query($queryParameters);
        }

        return $this->request('GET', $target, null, $headers);
    }

    public function postJson(string $url, array $payload, array $headers = []): array
    {
        return $this->request('POST', $url, $payload, $headers);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, string>  $headers
     * @return array<int|string, mixed>
     */
    private function request(string $method, string $url, ?array $payload, array $headers): array
    {
        if (! self::isSecureUrl($url)) {
            throw new DataSourceUnavailableException('http', 'Requests are sent over HTTPS only (plain HTTP only to localhost).');
        }

        $options = ['headers' => $headers];

        if ($payload !== null) {
            $options['json'] = $payload;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            $contents = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new DataSourceUnavailableException('http', 'The data source did not respond.', $exception);
        }

        if ($status < 200 || $status >= 300) {
            throw new DataSourceUnavailableException('http', 'The data source returned HTTP '.$status.'.', httpStatus: $status);
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DataSourceUnavailableException('http', 'The data source did not return JSON.', $exception);
        }

        if (! is_array($decoded)) {
            throw new DataSourceUnavailableException('http', 'The data source did not return a JSON object or list.');
        }

        return $decoded;
    }
}
