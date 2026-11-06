<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Data\NpmPackageMetadataData;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;

/**
 * Public npm registry metadata (#153), one GET per package:
 * `https://registry.npmjs.org/<name>`, a scoped name as `@scope%2Fname`.
 *
 * Only the package name leaves the machine: no version, no header beyond
 * the abbreviated-metadata `Accept`, which keeps the answer small (versions
 * and dist-tags without readmes). Timeouts, retries and the response cache
 * are the ones every data source shares.
 */
final class NpmRegistryService
{
    /**
     * npm package names: an optional `@scope/`, then URL-safe characters.
     * Old packages may still use capitals.
     */
    private const PACKAGE_NAME_PATTERN = '#^(?:@[A-Za-z0-9~-][A-Za-z0-9._~-]*/)?[A-Za-z0-9~-][A-Za-z0-9._~-]*$#';

    private const ABBREVIATED_METADATA = 'application/vnd.npm.install-v1+json';

    public function __construct(
        private readonly HttpClientContract $httpClient,
        private readonly ResponseCacheService $responseCache,
    ) {}

    /**
     * The published versions and the `latest` dist-tag. A package the
     * registry does not know (HTTP 404) has no versions, so nothing is newer.
     *
     * @throws DataSourceUnavailableException when the name is not an npm package name or the registry did not answer
     */
    public function metadata(string $packageName): NpmPackageMetadataData
    {
        if (preg_match(self::PACKAGE_NAME_PATTERN, $packageName) !== 1 || strlen($packageName) > 214) {
            throw new DataSourceUnavailableException('npm', 'Package name is not an npm package name.');
        }

        $url = 'https://registry.npmjs.org/'.str_replace('/', '%2F', $packageName);

        try {
            $document = $this->responseCache->remember(
                $url,
                fn (): array => $this->httpClient->getJson($url, [], ['Accept' => self::ABBREVIATED_METADATA]),
            );
        } catch (DataSourceUnavailableException $exception) {
            if ($exception->httpStatus === 404) {
                return new NpmPackageMetadataData([], null);
            }

            throw $exception;
        }

        $versions = [];

        foreach (array_keys(is_array($document['versions'] ?? null) ? $document['versions'] : []) as $version) {
            $versions[] = (string) $version;
        }

        $distributionTags = $document['dist-tags'] ?? null;
        $latest = is_array($distributionTags) && is_string($distributionTags['latest'] ?? null) ? $distributionTags['latest'] : null;

        return new NpmPackageMetadataData($versions, $latest);
    }

    /**
     * Same as `metadata`, except a data-source outage returns null.
     */
    public function metadataIfAvailable(string $packageName): ?NpmPackageMetadataData
    {
        try {
            return $this->metadata($packageName);
        } catch (DataSourceUnavailableException) {
            return null;
        }
    }
}
