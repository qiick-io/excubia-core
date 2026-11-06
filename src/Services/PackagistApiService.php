<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Data\ComposerAdvisoryData;
use Qiick\Excubia\Data\PackagistVersionData;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;

/**
 * Packagist metadata and security advisories.
 *
 * Advisories go out as one request for every package name. Metadata is one
 * request per package because that is the p2 API. Only names are sent.
 * Parsed metadata is kept for the run, so the checks that read it
 * (`composer-abandoned`, `composer-stale`, `laravel-next-blocker`,
 * `composer-outdated`) share one request per package even when the response
 * cache is bypassed.
 */
final class PackagistApiService
{
    /** @var array<string, list<PackagistVersionData>> */
    private array $versionsByPackageName = [];

    public function __construct(
        private readonly HttpClientContract $httpClient,
        private readonly ResponseCacheService $responseCache,
    ) {}

    /**
     * @param  list<string>  $packageNames
     * @return list<ComposerAdvisoryData>
     */
    public function securityAdvisories(array $packageNames): array
    {
        $packageNames = $this->packageNames($packageNames);

        if ($packageNames === []) {
            return [];
        }

        $url = 'https://packagist.org/api/security-advisories/';
        $document = $this->responseCache->remember(
            $url.'?'.implode(',', $packageNames),
            fn (): array => $this->httpClient->getJson($url, ['packages' => $packageNames]),
        );
        $advisories = [];
        $grouped = $document['advisories'] ?? [];

        if (! is_array($grouped)) {
            return [];
        }

        foreach ($grouped as $packageAdvisories) {
            if (! is_array($packageAdvisories)) {
                continue;
            }

            foreach ($packageAdvisories as $advisory) {
                if (! is_array($advisory) || ! is_string($advisory['advisoryId'] ?? null) || ! is_string($advisory['packageName'] ?? null)) {
                    continue;
                }

                $advisories[] = new ComposerAdvisoryData(
                    advisoryId: $advisory['advisoryId'],
                    packageName: $advisory['packageName'],
                    affectedVersions: is_string($advisory['affectedVersions'] ?? null) ? $advisory['affectedVersions'] : '',
                    severity: is_string($advisory['severity'] ?? null) ? $advisory['severity'] : null,
                    link: is_string($advisory['link'] ?? null) ? $advisory['link'] : null,
                );
            }
        }

        return $advisories;
    }

    /**
     * Same as `securityAdvisories`, except a data-source outage returns null.
     *
     * @param  list<string>  $packageNames
     * @return list<ComposerAdvisoryData>|null
     */
    public function securityAdvisoriesIfAvailable(array $packageNames): ?array
    {
        try {
            return $this->securityAdvisories($packageNames);
        } catch (DataSourceUnavailableException) {
            return null;
        }
    }

    /**
     * @return list<PackagistVersionData>
     */
    public function versions(string $packageName): array
    {
        $packageName = $this->packageNames([$packageName])[0] ?? null;

        if ($packageName === null) {
            throw new DataSourceUnavailableException('packagist', 'Package name is not a Composer package.');
        }

        if (isset($this->versionsByPackageName[$packageName])) {
            return $this->versionsByPackageName[$packageName];
        }

        $url = 'https://repo.packagist.org/p2/'.$packageName.'.json';
        $document = $this->responseCache->remember($url, fn (): array => $this->httpClient->getJson($url));
        $packages = $document['packages'] ?? null;

        $published = is_array($packages) ? ($packages[$packageName] ?? null) : null;

        if (! is_array($published)) {
            return $this->versionsByPackageName[$packageName] = [];
        }

        $versions = [];

        foreach ($published as $version) {
            if (! is_array($version) || ! is_string($version['version'] ?? null)) {
                continue;
            }

            $source = $version['source'] ?? null;
            $requirements = [];

            foreach (is_array($version['require'] ?? null) ? $version['require'] : [] as $name => $constraint) {
                if (is_string($name) && is_string($constraint)) {
                    $requirements[$name] = $constraint;
                }
            }

            $abandoned = $version['abandoned'] ?? null;

            if (! is_string($abandoned) && ! is_bool($abandoned)) {
                $abandoned = null;
            }

            $versions[] = new PackagistVersionData(
                version: $version['version'],
                releasedAt: is_string($version['time'] ?? null) ? $version['time'] : null,
                abandoned: $abandoned,
                sourceUrl: is_array($source) && is_string($source['url'] ?? null) ? $source['url'] : null,
                requirements: $requirements,
            );
        }

        return $this->versionsByPackageName[$packageName] = $versions;
    }

    /**
     * Same as `versions`, except a data-source outage returns null.
     *
     * @return list<PackagistVersionData>|null
     */
    public function versionsIfAvailable(string $packageName): ?array
    {
        try {
            return $this->versions($packageName);
        } catch (DataSourceUnavailableException) {
            return null;
        }
    }

    /**
     * @param  list<string>  $packageNames
     * @return list<string>
     */
    private function packageNames(array $packageNames): array
    {
        $accepted = [];

        foreach ($packageNames as $packageName) {
            if (preg_match('/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/', $packageName) !== 1) {
                continue;
            }

            $accepted[] = $packageName;
        }

        return array_values(array_unique($accepted));
    }
}
