<?php

declare(strict_types=1);

namespace Qiick\Excubia\Parsers;

use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Data\PlatformRequirementData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * Reads `composer.lock` packages, the locked platform PHP version and the
 * PHP / extension requirements for `php-platform-requirements`.
 *
 * Packages from `packages-dev` are marked as development dependencies.
 * Direct versus transitive is decided by the caller, which knows
 * `composer.json`.
 *
 * A package is public only when Composer recorded Packagist's download
 * notification URL for it. Path and artifact dists, VCS repositories, Satis,
 * Private Packagist and entries without that URL are private, so their names
 * are never sent to Packagist or GitHub.
 */
final class ComposerLockParser
{
    private const PACKAGIST_NOTIFICATION_URL = 'https://packagist.org/downloads/';

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $directPackageNames
     * @return array{
     *     packages: list<LockedPackageData>,
     *     platformPhpVersion: string|null,
     *     platformRequirements: list<PlatformRequirementData>,
     *     providedPlatformNames: list<string>,
     *     platformOverrideNames: list<string>
     * }
     */
    public function parse(array $document, array $directPackageNames): array
    {
        $direct = array_fill_keys($directPackageNames, true);
        $packages = [
            ...$this->lockedPackages($document['packages'] ?? [], false, $direct),
            ...$this->lockedPackages($document['packages-dev'] ?? [], true, $direct),
        ];
        $requirements = [
            ...$this->platformLinks($document['platform'] ?? null, PlatformRequirementData::ROOT_PACKAGE, false),
            ...$this->platformLinks($document['platform-dev'] ?? null, PlatformRequirementData::ROOT_PACKAGE, true),
        ];
        $providedNames = [];

        foreach (['packages' => false, 'packages-dev' => true] as $key => $isDevelopmentDependency) {
            // The entries were validated by lockedPackages() above.
            foreach (is_array($document[$key] ?? null) ? $document[$key] : [] as $package) {
                if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                    continue;
                }

                $name = $package['name'];
                $requirements = [...$requirements, ...$this->platformLinks($package['require'] ?? null, $name, $isDevelopmentDependency)];

                foreach ([...$this->platformLinks($package['provide'] ?? null, $name, false), ...$this->platformLinks($package['replace'] ?? null, $name, false)] as $provided) {
                    $providedNames[$provided->name] = $provided->name;
                }
            }
        }

        return [
            'packages' => $packages,
            'platformPhpVersion' => $this->platformPhpVersion($document),
            'platformRequirements' => $requirements,
            'providedPlatformNames' => array_values($providedNames),
            'platformOverrideNames' => array_map(
                static fn (PlatformRequirementData $override): string => $override->name,
                $this->platformLinks($document['platform-overrides'] ?? null, PlatformRequirementData::ROOT_PACKAGE, false),
            ),
        ];
    }

    /**
     * The `php` and `ext-*` entries of a link map (`require`, `provide`,
     * `platform`, …). Other platform names (`lib-*`, `composer-*`, `php-64bit`)
     * and packages are left out. A map or entry of the wrong type is skipped
     * rather than failing the scan: these keys only feed one check.
     *
     * ponytail: `lib-*` and `php-64bit` / `php-zts` style requirements are not
     * checked; add them here and in `PhpPlatformRequirementsCheck` if needed.
     *
     * @return list<PlatformRequirementData>
     */
    private function platformLinks(mixed $links, string $requiredBy, bool $isDevelopmentDependency): array
    {
        if (! is_array($links)) {
            return [];
        }

        $requirements = [];

        foreach ($links as $name => $constraint) {
            $name = strtolower((string) $name);

            if (! is_string($constraint) || ($name !== 'php' && ! str_starts_with($name, 'ext-'))) {
                continue;
            }

            $requirements[] = new PlatformRequirementData($name, $constraint, $requiredBy, $isDevelopmentDependency);
        }

        return $requirements;
    }

    /**
     * @param  array<string, bool>  $directPackageNames
     * @return list<LockedPackageData>
     */
    private function lockedPackages(mixed $packages, bool $isDevelopmentDependency, array $directPackageNames): array
    {
        if ($packages === []) {
            return [];
        }

        if (! is_array($packages) || ! array_is_list($packages)) {
            throw new InvalidConfigurationException('composer.lock packages must be a list.');
        }

        $lockedPackages = [];

        foreach ($packages as $package) {
            if (! is_array($package)) {
                throw new InvalidConfigurationException('composer.lock package entries must be objects.');
            }

            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;

            if (! is_string($name) || $name === '' || ! is_string($version) || $version === '') {
                throw new InvalidConfigurationException('composer.lock package entries need a name and a version.');
            }

            $lockedPackages[] = new LockedPackageData(
                name: $name,
                version: $version,
                isDirectDependency: isset($directPackageNames[$name]),
                isDevelopmentDependency: $isDevelopmentDependency,
                isPrivate: $this->isPrivate($package),
            );
        }

        return $lockedPackages;
    }

    /**
     * True unless Packagist served the entry. A local dist type wins over the
     * notification URL; a missing URL counts as private.
     *
     * @param  array<mixed>  $package
     */
    private function isPrivate(array $package): bool
    {
        $dist = $package['dist'] ?? null;

        if (is_array($dist) && in_array($dist['type'] ?? null, ['path', 'artifact'], true)) {
            return true;
        }

        return ($package['notification-url'] ?? null) !== self::PACKAGIST_NOTIFICATION_URL;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function platformPhpVersion(array $document): ?string
    {
        $platform = $document['platform'] ?? null;

        if ($platform === null) {
            return null;
        }

        if (! is_array($platform)) {
            throw new InvalidConfigurationException('composer.lock platform must be an object.');
        }

        $phpVersion = $platform['php'] ?? null;

        if ($phpVersion === null) {
            return null;
        }

        if (! is_string($phpVersion) || $phpVersion === '') {
            throw new InvalidConfigurationException('composer.lock platform.php must be a version string.');
        }

        return $phpVersion;
    }
}
