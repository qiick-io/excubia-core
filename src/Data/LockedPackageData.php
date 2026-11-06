<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

/**
 * One package pinned by Composer or by an npm lockfile.
 *
 * `version` is the string from the lockfile, not a normalized semver, so
 * later checks can show the version the project actually installed.
 * `isPrivate` is true when the lockfile says the package does not come from
 * Packagist or the public npm registry (path, artifact, VCS, Satis or a
 * private registry): its name must not be sent to a public data source
 * (`docs/SPEC.md` section 7).
 * `licenses` are the license entries the lockfile declares, each an SPDX id
 * or expression as written; more than one entry means the package offers a
 * choice (Composer's `license` array). Empty means none was declared.
 */
final readonly class LockedPackageData
{
    /**
     * A license string longer than this, or with characters SPDX never
     * uses, is dropped so free text from a lockfile cannot reach the report.
     */
    private const LICENSE_PATTERN = '#^[A-Za-z0-9.+:() -]{1,128}$#';

    /**
     * @param  list<string>  $licenses
     */
    public function __construct(
        public string $name,
        public string $version,
        public bool $isDirectDependency,
        public bool $isDevelopmentDependency,
        public bool $isPrivate = false,
        public array $licenses = [],
    ) {}

    /**
     * The license entries of a lockfile value, for every parser to share.
     *
     * Accepts a string (`MIT`, `(MIT OR Apache-2.0)`), a list of strings
     * (Composer), and the legacy npm objects `{"type": "MIT"}` alone or in a
     * list. Anything else, empty strings and strings that fail
     * `LICENSE_PATTERN` (paths, URLs, free text) are left out.
     *
     * @return list<string>
     */
    public static function licensesFrom(mixed $declared): array
    {
        if (is_string($declared) || (is_array($declared) && ! array_is_list($declared))) {
            $declared = [$declared];
        }

        if (! is_array($declared)) {
            return [];
        }

        $licenses = [];

        foreach ($declared as $entry) {
            if (is_array($entry)) {
                $entry = $entry['type'] ?? null;
            }

            if (! is_string($entry)) {
                continue;
            }

            $entry = trim($entry);

            if (preg_match(self::LICENSE_PATTERN, $entry) === 1 && ! in_array($entry, $licenses, true)) {
                $licenses[] = $entry;
            }
        }

        return $licenses;
    }
}
