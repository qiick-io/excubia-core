<?php

declare(strict_types=1);

namespace Qiick\Excubia\Parsers;

use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * Reads `yarn.lock` from Yarn classic (v1) and Yarn Berry (v2+) (#21).
 *
 * Berry writes YAML with a `__metadata` block; classic writes its own
 * format (`"name@range", "name@range":` then `  version "1.0.0"`), read
 * line by line here. The project root (`workspace:.`) is not a package.
 *
 * Privacy (`docs/SPEC.md` section 7): a classic entry is public only when
 * its `resolved` tarball is on the public npm registry. A Berry entry is
 * public only when it resolved through the `npm:` protocol and
 * `.yarnrc.yml` does not point that package's scope, or the default
 * registry, at another server. `workspace:`, `file:`, `link:`, `portal:`,
 * `patch:`, git and URL resolutions are private.
 *
 * Neither format records licenses or whether a package is a development
 * dependency, so `licenses` stays empty and `isDevelopmentDependency`
 * false: `dependency-licenses` reports these packages in one summary row.
 */
final class YarnLockParser
{
    public function __construct(private readonly YamlSubsetParser $yamlParser = new YamlSubsetParser) {}

    /**
     * @param  list<string>  $directPackageNames
     * @return list<LockedPackageData>
     *
     * @throws InvalidConfigurationException
     */
    public function parse(string $contents, ?string $yarnrcContents, array $directPackageNames): array
    {
        $direct = array_fill_keys($directPackageNames, true);

        if (preg_match('/^__metadata:/m', $contents) === 1) {
            return $this->berryPackages($contents, $this->privateRegistryScopes($yarnrcContents), $direct);
        }

        return $this->classicPackages($contents, $direct);
    }

    /**
     * @param  array<string, true>  $direct
     * @return list<LockedPackageData>
     */
    private function classicPackages(string $contents, array $direct): array
    {
        $specifiers = [];
        $fields = [];
        $current = null;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (! str_starts_with($line, ' ')) {
                if (! str_ends_with($line, ':')) {
                    throw new InvalidConfigurationException('yarn.lock is not a Yarn lockfile.');
                }

                $specifiers[] = trim(trim(explode(',', substr($line, 0, -1), 2)[0]), '"');
                $fields[] = [];
                $current = array_key_last($specifiers);

                continue;
            }

            if ($current === null || str_starts_with($line, '    ') || ! preg_match('/^  ([A-Za-z]+) (.+)$/', $line, $matches)) {
                continue;
            }

            $fields[$current][$matches[1]] = $this->classicValue($matches[2]);
        }

        $packages = [];

        foreach ($specifiers as $index => $specifier) {
            $nameAndReference = PackageLockParser::nameAndReference($specifier);
            $version = $fields[$index]['version'] ?? '';

            if ($nameAndReference === null || $version === '') {
                continue;
            }

            [$name, $reference] = $nameAndReference;

            if (str_starts_with($reference, 'npm:')) {
                $name = PackageLockParser::nameAndReference(substr($reference, 4))[0] ?? $name;
            }

            $resolved = $fields[$index]['resolved'] ?? null;
            $packages[] = $this->package($name, $version, $direct, $resolved === null || ! PackageLockParser::isPublicRegistryUrl($resolved));
        }

        return $packages;
    }

    /**
     * A classic field value: a JSON-style quoted string or a bare word.
     */
    private function classicValue(string $value): string
    {
        $value = trim($value);

        if (! str_starts_with($value, '"')) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return is_string($decoded) ? $decoded : trim($value, '"');
    }

    /**
     * @param  list<string>  $privateRegistryScopes
     * @param  array<string, true>  $direct
     * @return list<LockedPackageData>
     */
    private function berryPackages(string $contents, array $privateRegistryScopes, array $direct): array
    {
        $packages = [];

        foreach ($this->yamlParser->parse($contents, 'yarn.lock') as $key => $entry) {
            if ($key === '__metadata') {
                continue;
            }

            if (! is_array($entry)) {
                throw new InvalidConfigurationException('yarn.lock package entries must be mappings.');
            }

            $resolution = $entry['resolution'] ?? null;
            $version = $entry['version'] ?? null;
            $nameAndReference = is_string($resolution) ? PackageLockParser::nameAndReference($resolution) : null;

            if ($nameAndReference === null || ! is_string($version) || $version === '' || $nameAndReference[1] === 'workspace:.') {
                continue;
            }

            [$name, $reference] = $nameAndReference;
            $isPrivate = ! str_starts_with($reference, 'npm:') || PackageLockParser::isServedByPrivateRegistry($name, $privateRegistryScopes);
            $packages[] = $this->package($name, $version, $direct, $isPrivate);
        }

        return $packages;
    }

    /**
     * Scopes `.yarnrc.yml` serves from a registry other than the public
     * one: `npmScopes.<scope>.npmRegistryServer`, plus the default scope when
     * the top-level `npmRegistryServer` is not public. The URLs are only
     * compared, never stored or reported.
     *
     * @return list<string>
     */
    private function privateRegistryScopes(?string $yarnrcContents): array
    {
        if ($yarnrcContents === null) {
            return [];
        }

        $configuration = $this->yamlParser->parse($yarnrcContents, '.yarnrc.yml');
        $scopes = [];
        $defaultServer = $configuration['npmRegistryServer'] ?? null;

        if (is_string($defaultServer) && ! PackageLockParser::isPublicRegistryUrl($defaultServer)) {
            $scopes[] = PackageLockParser::DEFAULT_REGISTRY_SCOPE;
        }

        $npmScopes = $configuration['npmScopes'] ?? null;

        foreach (is_array($npmScopes) ? $npmScopes : [] as $scope => $settings) {
            $server = is_array($settings) ? ($settings['npmRegistryServer'] ?? null) : null;

            if (is_string($server) && ! PackageLockParser::isPublicRegistryUrl($server)) {
                $scopes[] = '@'.strtolower(ltrim((string) $scope, '@'));
            }
        }

        return $scopes;
    }

    /**
     * @param  array<string, true>  $direct
     */
    private function package(string $name, string $version, array $direct, bool $isPrivate): LockedPackageData
    {
        return new LockedPackageData(
            name: $name,
            version: $version,
            isDirectDependency: isset($direct[$name]),
            isDevelopmentDependency: false,
            isPrivate: $isPrivate,
        );
    }
}
