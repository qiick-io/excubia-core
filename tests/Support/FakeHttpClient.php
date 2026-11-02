<?php

declare(strict_types=1);

namespace Qiick\Excubia\Tests\Support;

use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;

/**
 * HTTP stand-in for tests. It only returns values it was given, so a test
 * that uses it cannot open a socket.
 */
final class FakeHttpClient implements HttpClientContract
{
    public int $calls = 0;

    /** @var array<string, string> */
    public array $lastHeaders = [];

    /** @var array<string, scalar|list<scalar>|null> */
    public array $lastQuery = [];

    public ?string $lastPostUrl = null;

    /** @var list<array<string, mixed>> */
    public array $postedPayloads = [];

    /**
     * @param  array<string, array<int|string, mixed>>  $responses
     * @param  array<string, DataSourceUnavailableException>  $failures  thrown instead of a response, e.g. an HTTP 401
     */
    public function __construct(
        private readonly array $responses,
        private readonly array $failures = [],
    ) {}

    public function getJson(string $url, array $queryParameters = [], array $headers = []): array
    {
        $this->calls++;
        $this->lastHeaders = $headers;
        $this->lastQuery = $queryParameters;

        return $this->responseFor($url);
    }

    public function postJson(string $url, array $payload, array $headers = []): array
    {
        $this->calls++;
        $this->lastHeaders = $headers;
        $this->lastPostUrl = $url;
        $this->postedPayloads[] = $payload;

        return $this->responseFor($url);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function responseFor(string $url): array
    {
        if (isset($this->failures[$url])) {
            throw $this->failures[$url];
        }

        if (! isset($this->responses[$url])) {
            throw new DataSourceUnavailableException('fake', 'No recorded response for '.$url.'.');
        }

        return $this->responses[$url];
    }
}
