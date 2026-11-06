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
 * registry (`registry.npmjs.org`, or its `registry.yarnpkg.com` mirror). Links (`link: true`), `file:` and VCS dependencies, other
 * registries and entries without `resolved` (workspace members) are
 * private, so their names are never sent to OSV.
 *
 * Licenses come from the entry's `license` field (an SPDX expression), or
 * the legacy `licenses` list of `{"type": …}` objects some old packages
 * still declare.
 *
 * The static helpers hold the npm registry rules the Yarn and pnpm parsers
 * share with this one (#21).
 */
final class PackageLockParser
{
    /**
     * Hosts of the public npm registry. `registry.yarnpkg.com` serves the
     * same packages, so a Yarn tarball from it is public as well.
     */
    private const PUBLIC_REGISTRY_HOSTS = ['registry.npmjs.org', 'registry.yarnpkg.com'];

    /**
     * Scope key, in a private registry scope list, for the default registry.
     */
    public const DEFAULT_REGISTRY_SCOPE = '*';

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

        return ! self::isPublicRegistryUrl($resolved);
    }

    /**
     * True when `$url` is an HTTPS URL on the public npm registry.
     */
    public static function isPublicRegistryUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }

        return in_array(strtolower($parts['host'] ?? ''), self::PUBLIC_REGISTRY_HOSTS, true);
    }

    /**
     * Splits `name@reference` (`lodash@^4.17.20`, `@scope/name@npm:1.0.0`)
     * at the `@` after the name, or returns null when there is none.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function nameAndReference(string $specifier): ?array
    {
        $position = strpos($specifier, '@', str_starts_with($specifier, '@') ? 1 : 0);

        if ($position === false || $position === 0) {
            return null;
        }

        return [substr($specifier, 0, $position), substr($specifier, $position + 1)];
    }

    /**
     * True when the registry configuration sends `$name` to a registry other
     * than the public one: its scope (`@acme`) is listed, or the default
     * registry itself is (`DEFAULT_REGISTRY_SCOPE`, which makes every
     * package private, even a scope pointed back at the public registry).
     *
     * @param  list<string>  $privateRegistryScopes
     */
    public static function isServedByPrivateRegistry(string $name, array $privateRegistryScopes): bool
    {
        $scope = str_starts_with($name, '@') ? strtolower(explode('/', $name, 2)[0]) : null;

        if ($scope !== null && in_array($scope, $privateRegistryScopes, true)) {
            return true;
        }

        return in_array(self::DEFAULT_REGISTRY_SCOPE, $privateRegistryScopes, true);
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
