<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Data\GitHubRepositoryData;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;

/**
 * Reads archived and last-push for one public repository.
 *
 * `GITHUB_TOKEN` is read from the environment at call time, sent as a
 * header, and never included in the cache key or the stored body.
 */
final class GitHubRepositoryApiService
{
    public function __construct(
        private readonly HttpClientContract $httpClient,
        private readonly ResponseCacheService $responseCache,
    ) {}

    /**
     * @throws DataSourceUnavailableException
     */
    public function repository(string $owner, string $name): GitHubRepositoryData
    {
        if (! self::isPathSegment($owner) || ! self::isPathSegment($name)) {
            throw new DataSourceUnavailableException('github', 'Repository name is not a GitHub owner and name.');
        }

        $url = 'https://api.github.com/repos/'.$owner.'/'.$name;
        $headers = [];
        $token = getenv('GITHUB_TOKEN');

        if (is_string($token) && $token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $document = $this->responseCache->remember($url, fn (): array => $this->httpClient->getJson($url, [], $headers));

        return new GitHubRepositoryData(
            fullName: is_string($document['full_name'] ?? null) ? $document['full_name'] : $owner.'/'.$name,
            isArchived: ($document['archived'] ?? false) === true,
            pushedAt: is_string($document['pushed_at'] ?? null) ? $document['pushed_at'] : null,
        );
    }

    /**
     * Same as `repository`, except a data-source outage returns null.
     */
    public function repositoryIfAvailable(string $owner, string $name): ?GitHubRepositoryData
    {
        try {
            return $this->repository($owner, $name);
        } catch (DataSourceUnavailableException) {
            return null;
        }
    }

    /**
     * A GitHub owner or repository name. `.` and `..` are refused so a
     * source URL from Packagist cannot walk the API path.
     */
    private static function isPathSegment(string $segment): bool
    {
        return preg_match('/^[A-Za-z0-9_.-]+$/', $segment) === 1 && trim($segment, '.') !== '';
    }
}
