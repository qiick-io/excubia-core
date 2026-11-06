<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * The `checks.dependency-licenses` keys of `excubia.json`.
 *
 * `allowedLicenses` and `deniedLicenses` are SPDX ids or globs (`*` any
 * characters, `?` exactly one, case-insensitive, whole id). A denied
 * license fails the check at high; an allowed one never warns. Denied wins
 * when both match. Development dependencies are left out unless
 * `isDevelopmentDependencyIncluded` is true (`includeDev`, default false).
 */
final readonly class DependencyLicensesConfigurationData
{
    private const LICENSE_PATTERN_SYNTAX = '#^[A-Za-z0-9.+:*?-]+$#';

    /**
     * @param  list<string>  $allowedLicenses
     * @param  list<string>  $deniedLicenses
     *
     * @throws InvalidConfigurationException
     */
    public function __construct(
        public array $allowedLicenses = [],
        public array $deniedLicenses = [],
        public bool $isDevelopmentDependencyIncluded = false,
    ) {
        foreach (['allowed' => $this->allowedLicenses, 'denied' => $this->deniedLicenses] as $key => $patterns) {
            foreach ($patterns as $index => $pattern) {
                if (preg_match(self::LICENSE_PATTERN_SYNTAX, $pattern) !== 1) {
                    throw new InvalidConfigurationException('checks.dependency-licenses.'.$key.'['.$index.'] must be an SPDX license id or glob: letters, digits, . + : - and the wildcards * and ?.');
                }
            }
        }
    }

    /**
     * True when an `allowed` entry matches one of the spellings of a license id.
     *
     * @param  list<string>  $licenseIdSpellings
     */
    public function isAllowed(array $licenseIdSpellings): bool
    {
        return $this->matchesAny($this->allowedLicenses, $licenseIdSpellings);
    }

    /**
     * True when a `denied` entry matches one of the spellings of a license id.
     *
     * @param  list<string>  $licenseIdSpellings
     */
    public function isDenied(array $licenseIdSpellings): bool
    {
        return $this->matchesAny($this->deniedLicenses, $licenseIdSpellings);
    }

    /**
     * @param  list<string>  $patterns
     * @param  list<string>  $values
     */
    private function matchesAny(array $patterns, array $values): bool
    {
        foreach ($patterns as $pattern) {
            foreach ($values as $value) {
                if (ExcubiaConfigurationData::matchesGlob($pattern, $value)) {
                    return true;
                }
            }
        }

        return false;
    }
}
