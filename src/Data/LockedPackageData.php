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
 */
final readonly class LockedPackageData
{
    public function __construct(
        public string $name,
        public string $version,
        public bool $isDirectDependency,
        public bool $isDevelopmentDependency,
        public bool $isPrivate = false,
    ) {}
}
