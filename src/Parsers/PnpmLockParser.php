<?php

declare(strict_types=1);

namespace Qiick\Excubia\Parsers;

use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * Reads `pnpm-lock.yaml` lockfileVersion 6 (pnpm 8) and 9 (pnpm 9 and 10)
 * (#21).
 *
 * Only the `packages` map is read. Version 6 keys are
 * `/name@version(peers)`, version 9 keys `name@version`; an entry's own
 * `name` and `version` win when set (directories, tarballs, git).
 * Workspace members (`link:`) are importers, not packages.
 *
 * Privacy (`docs/SPEC.md` section 7): an entry is public only when it is a
 * registry package (version 6: the key starts with `/`; version 9: the
 * reference is a version), its `resolution` has no `directory`, `repo`,
 * `commit`, `type` or off-registry `tarball`, and the project `.npmrc`
 * does not point its scope, or the default registry, at another server.
 *
 * pnpm records no licenses, so `licenses` stays empty and
 * `dependency-licenses` reports these packages in one summary row.
 * Version 6 marks dev-only packages with `dev: true`; version 9 does not
 * record it, so `isDevelopmentDependency` is false there.
 *
 * ponytail: version 9 dev-only packages could be found by walking
 * `snapshots` from the importers' `devDependencies`; nothing needs it yet,
 * because npm packages are kept under `--no-dev` and pnpm has no licenses.
 */
final class PnpmLockParser
{
    public function __construct(private readonly YamlSubsetParser $yamlParser = new YamlSubsetParser) {}

    /**
     * @param  list<string>  $directPackageNames
     * @return list<LockedPackageData>
     *
     * @throws InvalidConfigurationException
     */
    public function parse(string $contents, ?string $npmrcContents, array $directPackageNames): array
    {
        $document = $this->yamlParser->parse($contents, 'pnpm-lock.yaml');
        $lockfileVersion = $document['lockfileVersion'] ?? null;
        $major = is_string($lockfileVersion) ? (int) explode('.', $lockfileVersion)[0] : 0;

        if ($major !== 6 && $major !== 9) {
            throw new InvalidConfigurationException('pnpm-lock.yaml lockfileVersion must be 6 or 9.');
        }

        $entries = $document['packages'] ?? [];

        if (! is_array($entries) || (array_is_list($entries) && $entries !== [])) {
            throw new InvalidConfigurationException('pnpm-lock.yaml packages must be a mapping.');
        }

        $privateRegistryScopes = $this->privateRegistryScopes($npmrcContents);
        $direct = array_fill_keys($directPackageNames, true);
        $packages = [];

        foreach ($entries as $key => $entry) {
            $entry ??= [];

            if (! is_array($entry)) {
                throw new InvalidConfigurationException('pnpm-lock.yaml package entries must be mappings.');
            }

            $package = $this->package((string) $key, $entry, $major, $privateRegistryScopes, $direct);

            if ($package !== null) {
                $packages[$package->name."\0".$package->version] ??= $package;
            }
        }

        return array_values($packages);
    }

    /**
     * @param  array<int|string, mixed>  $entry
     * @param  list<string>  $privateRegistryScopes
     * @param  array<string, true>  $direct
     */
    private function package(string $key, array $entry, int $major, array $privateRegistryScopes, array $direct): ?LockedPackageData
    {
        $isRegistryKey = $major === 9 || str_starts_with($key, '/');
        $parenthesis = strpos($key, '(');
        $specifier = $parenthesis === false ? $key : substr($key, 0, $parenthesis);

        if ($major === 6) {
            $specifier = ltrim($specifier, '/');
            $at = strrpos($specifier, '@');
            $nameAndReference = $isRegistryKey && $at !== false && $at > 0
                ? [substr($specifier, 0, $at), substr($specifier, $at + 1)]
                : null;
        } else {
            $nameAndReference = PackageLockParser::nameAndReference($specifier);
        }

        $name = is_string($entry['name'] ?? null) ? $entry['name'] : ($nameAndReference[0] ?? null);
        $reference = $nameAndReference[1] ?? '';
        $version = is_string($entry['version'] ?? null) ? $entry['version'] : $reference;

        if ($name === null || $name === '' || preg_match('/^\d/', $version) !== 1) {
            return null;
        }

        $isRegistryPackage = $isRegistryKey && preg_match('/^\d/', $reference) === 1;

        return new LockedPackageData(
            name: $name,
            version: $version,
            isDirectDependency: isset($direct[$name]),
            isDevelopmentDependency: ($entry['dev'] ?? false) === true,
            isPrivate: ! $isRegistryPackage
                || ! $this->isRegistryResolution($entry['resolution'] ?? null)
                || PackageLockParser::isServedByPrivateRegistry($name, $privateRegistryScopes),
        );
    }

    /**
     * True for a plain registry `resolution` (`{integrity: …}`), or one
     * whose `tarball` is on the public registry.
     */
    private function isRegistryResolution(mixed $resolution): bool
    {
        if (! is_array($resolution)) {
            return false;
        }

        foreach (['directory', 'repo', 'commit', 'type', 'path'] as $key) {
            if (array_key_exists($key, $resolution)) {
                return false;
            }
        }

        $tarball = $resolution['tarball'] ?? null;

        return $tarball === null || (is_string($tarball) && PackageLockParser::isPublicRegistryUrl($tarball));
    }

    /**
     * Scopes the project `.npmrc` serves from a registry other than the
     * public one: `@scope:registry=…`, plus the default scope for a
     * non-public `registry=…`.
     *
     * `.npmrc` often holds credentials (`//host/:_authToken=…`, `_auth`,
     * `_password`): only lines matching the registry pattern are looked at,
     * every other line is dropped on the spot and never kept in any object.
     * The URLs are only compared, never stored or reported.
     *
     * @return list<string>
     */
    private function privateRegistryScopes(?string $npmrcContents): array
    {
        $scopes = [];

        foreach (preg_split('/\R/', $npmrcContents ?? '') ?: [] as $line) {
            if (preg_match('/^\s*(@[^:\s]+:)?registry\s*=\s*(\S+)\s*$/', $line, $matches) !== 1) {
                continue;
            }

            if (PackageLockParser::isPublicRegistryUrl(trim($matches[2], '"\''))) {
                continue;
            }

            $scopes[] = $matches[1] === '' ? PackageLockParser::DEFAULT_REGISTRY_SCOPE : strtolower(rtrim($matches[1], ':'));
        }

        return $scopes;
    }
}
