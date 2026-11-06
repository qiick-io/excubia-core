<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use UnexpectedValueException;

/**
 * Composer constraint and version checks live here so health checks do not depend on
 * the semver library directly.
 */
final class ComposerConstraintService
{
    /**
     * True when `$version` satisfies the Composer `$constraint`.
     *
     * Empty or invalid constraints return false instead of throwing.
     */
    public function isSatisfiedBy(string $version, string $constraint): bool
    {
        if ($constraint === '') {
            return false;
        }

        try {
            return Semver::satisfies($version, $constraint);
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    /**
     * Composer's normalized form (`v7.4.1` is `7.4.1.0`, `2.0.0-beta1` is
     * `2.0.0.0-beta1`), or null when Composer does not read it as a version.
     */
    public function normalizedVersion(string $version): ?string
    {
        try {
            return (new VersionParser)->normalize($version);
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    /**
     * `stable`, `RC`, `beta`, `alpha` or `dev`, as Composer reads the version.
     */
    public function stability(string $version): string
    {
        return VersionParser::parseStability($version);
    }

    /**
     * True when the normalized `$version` is newer than the normalized `$other`.
     */
    public function isNewer(string $version, string $other): bool
    {
        return Comparator::greaterThan($version, $other);
    }

    /**
     * The newest release in the locked major.minor, in the locked major, and
     * in a later major, each only when it is newer than the locked version.
     * Values are the versions as the data source prints them (`v7.4.1`).
     *
     * Shared by `composer-outdated` and `npm-outdated`. `$lockedVersion` is
     * normalized. Only stable releases count, unless the locked version is
     * itself a pre-release; dev versions and strings Composer cannot read
     * never count. With `$latestVersion` (npm's `latest` dist-tag) a stable
     * release newer than it is ignored, so a release the registry has not
     * promoted is not advised.
     *
     * @param  list<string>  $versions
     * @return array{latestPatch: string|null, latestMinor: string|null, latestMajor: string|null}
     */
    public function newerReleases(array $versions, string $lockedVersion, ?string $latestVersion = null): array
    {
        $isPreReleaseAllowed = $this->stability($lockedVersion) !== 'stable';
        $upperBound = $latestVersion === null ? null : $this->normalizedVersion($latestVersion);
        [$lockedMajor, $lockedMinor] = $this->majorAndMinor($lockedVersion);
        /** @var array{latestPatch: array{string, string}|null, latestMinor: array{string, string}|null, latestMajor: array{string, string}|null} $newest */
        $newest = ['latestPatch' => null, 'latestMinor' => null, 'latestMajor' => null];

        foreach ($versions as $version) {
            $normalized = $this->normalizedVersion($version);

            if ($normalized === null || ! $this->isNewer($normalized, $lockedVersion)) {
                continue;
            }

            $stability = $this->stability($normalized);

            if ($stability === 'dev' || ($stability !== 'stable' && ! $isPreReleaseAllowed)) {
                continue;
            }

            if ($stability === 'stable' && $upperBound !== null && $this->isNewer($normalized, $upperBound)) {
                continue;
            }

            [$major, $minor] = $this->majorAndMinor($normalized);
            $slots = $major > $lockedMajor ? ['latestMajor'] : ($minor === $lockedMinor ? ['latestPatch', 'latestMinor'] : ['latestMinor']);

            foreach ($slots as $slot) {
                if ($newest[$slot] === null || $this->isNewer($normalized, $newest[$slot][0])) {
                    $newest[$slot] = [$normalized, $version];
                }
            }
        }

        return array_map(static fn (?array $release): ?string => $release === null ? null : $release[1], $newest);
    }

    /**
     * @return array{int, int}
     */
    private function majorAndMinor(string $normalizedVersion): array
    {
        $parts = explode('.', $normalizedVersion);

        return [(int) $parts[0], (int) ($parts[1] ?? 0)];
    }
}
