<?php

declare(strict_types=1);

namespace Qiick\Excubia\Tests\Support;

use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Services\SymfonyHttpClientService;

/**
 * An excubia-app stand-in with a body limit (#249): it answers HTTP 413 to a
 * push whose body, encoded like Symfony HttpClient encodes it, is larger
 * than `$maxBodyBytes`, and HTTP 503 to the first `$unavailableCount` pushes
 * that fit. Like the real app it stores a batch once per `batchId`.
 */
final class FakeSizeLimitedApp implements HttpClientContract
{
    /**
     * Every push in order, with its answer.
     *
     * @var list<array{batchId: string, bytes: int, status: int}>
     */
    public array $attempts = [];

    /**
     * Stored payloads by batch id.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $stored = [];

    public function __construct(
        private readonly int $maxBodyBytes,
        private int $unavailableCount = 0,
    ) {}

    public function getJson(string $url, array $queryParameters = [], array $headers = []): array
    {
        throw new DataSourceUnavailableException('fake', 'The size-limited app only accepts pushes.');
    }

    public function postJson(string $url, array $payload, array $headers = []): array
    {
        $bytes = strlen((string) json_encode($payload, SymfonyHttpClientService::JSON_BODY_FLAGS));
        $batchId = is_string($payload['batchId'] ?? null) ? $payload['batchId'] : '';
        $status = match (true) {
            $bytes > $this->maxBodyBytes => 413,
            $this->unavailableCount > 0 => 503,
            default => 200,
        };
        $this->attempts[] = ['batchId' => $batchId, 'bytes' => $bytes, 'status' => $status];

        if ($status !== 200) {
            if ($status === 503) {
                $this->unavailableCount--;
            }

            throw new DataSourceUnavailableException('http', 'The data source returned HTTP '.$status.'.', httpStatus: $status);
        }

        $this->stored[$batchId] ??= $payload;

        return ['stored' => true];
    }
}
