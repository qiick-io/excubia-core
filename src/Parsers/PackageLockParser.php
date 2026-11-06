<?php

declare(strict_types=1);

namespace Qiick\Excubia\Parsers;

use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * Reads npm lockfileVersion 2 and 3.
 *
 * The root package (the empty path) is the project, not a dependency.
 * Package names come from the entry's `name` when it is set, otherwise from
 * the last `node_modules/` segment so nested copies keep their own name.
 *
 * A package is public only when `resolved` is a tarball on the public npm
 * registry. Links (`link: true`), `file:` and VCS dependencies, other
 * registries and entries without `resolved` (workspace members) are
 * private, so their names are never sent to OSV.
 *
 * Licenses come from the entry's `license` field (an SPDX expression), or
 * the legacy `licenses` list of `{"type": …}` objects some old packages
 * still declare.
 */
final class PackageLockParser
{
    private const NPM_REGISTRY_URL = 'https://registry.npmjs.org/';

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $directPackageNames
     * @return list<LockedPackageData>
     */
    public function parse(array $document, array $directPackageNames): array
    {
        $lockfileVersion = $document['lockfileVersion'] ?? null;

        if ($lockfileVersion !== 2 && $lockfileVersion !== 3) {
            throw new InvalidConfigurationException('package-lock.json lockfileVersion must be 2 or 3.');
        }

        $packages = $document['packages'] ?? null;

        if (! is_array($packages)) {
            throw new InvalidConfigurationException('package-lock.json packages must be an object.');
        }

        $direct = array_fill_keys($directPackageNames, true);
        $lockedPackages = [];

        foreach ($packages as $path => $package) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            if (! is_array($package)) {
                throw new InvalidConfigurationException('package-lock.json package entries must be objects.');
            }

            $version = $package['version'] ?? null;

            if (! is_string($version) || $version === '') {
                continue;
            }

            $name = $package['name'] ?? null;

            if (! is_string($name) || $name === '') {
                $name = $this->nameFromPath($path);
            }

            if ($name === null) {
                continue;
            }

            $lockedPackages[] = new LockedPackageData(
                name: $name,
                version: $version,
                isDirectDependency: isset($direct[$name]),
                isDevelopmentDependency: ($package['dev'] ?? false) === true || ($package['devOptional'] ?? false) === true,
                isPrivate: $this->isPrivate($package),
                licenses: LockedPackageData::licensesFrom($package['license'] ?? $package['licenses'] ?? null),
            );
        }

        return $lockedPackages;
    }

    /**
     * @param  array<mixed>  $package
     */
    private function isPrivate(array $package): bool
    {
        $resolved = $package['resolved'] ?? null;

        if (($package['link'] ?? false) === true || ! is_string($resolved)) {
            return true;
        }

        return ! str_starts_with($resolved, self::NPM_REGISTRY_URL);
    }

    private function nameFromPath(string $path): ?string
    {
        $marker = 'node_modules/';
        $position = strrpos($path, $marker);

        if ($position === false) {
            return null;
        }

        $name = substr($path, $position + strlen($marker));

        if ($name === '') {
            return null;
        }

        return $name;
    }
}
