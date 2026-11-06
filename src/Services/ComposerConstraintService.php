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
}
