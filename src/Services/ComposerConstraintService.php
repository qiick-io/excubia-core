<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Composer\Semver\Semver;
use UnexpectedValueException;

/**
 * Composer constraint checks live here so health checks do not depend on
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
}
