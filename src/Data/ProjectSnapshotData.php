<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

/**
 * Manifests and lockfiles reduced to the values the checks are allowed to see.
 *
 * Paths, file contents, and hostnames are not stored. Only versions and
 * package names leave this object toward a data source. `laravelPosture`
 * is set only by the Artisan commands, which boot the app; the standalone
 * CLI leaves it null and the posture checks skip.
 */
final readonly class ProjectSnapshotData
{
    /**
     * @param  list<LockedPackageData>  $composerPackages
     * @param  list<LockedPackageData>  $npmPackages
     * @param  list<string>  $requiredPackageNames  package names in `composer.json` `require` (not `require-dev`)
     * @param  list<PlatformRequirementData>  $platformRequirements  `php` and `ext-*` requirements of the lockfile, without dev ones under `--no-dev`
     * @param  list<string>  $providedPlatformNames  `ext-*` / `php` names a locked package provides or replaces (polyfills)
     * @param  list<string>  $platformOverrideNames  names in the lockfile `platform-overrides` (`composer.json` `config.platform`)
     */
    public function __construct(
        public ?string $phpVersion,
        public ?string $laravelFrameworkVersion,
        public array $composerPackages,
        public array $npmPackages,
        public ?string $nodeVersionDeclaration,
        public bool $hasNpmLockfile = false,
        public ?LaravelPostureData $laravelPosture = null,
        public array $requiredPackageNames = [],
        public array $platformRequirements = [],
        public array $providedPlatformNames = [],
        public array $platformOverrideNames = [],
    ) {}

    /**
     * The same snapshot with the posture of the booted app attached.
     */
    public function withLaravelPosture(?LaravelPostureData $laravelPosture): self
    {
        return new self(
            phpVersion: $this->phpVersion,
            laravelFrameworkVersion: $this->laravelFrameworkVersion,
            composerPackages: $this->composerPackages,
            npmPackages: $this->npmPackages,
            nodeVersionDeclaration: $this->nodeVersionDeclaration,
            hasNpmLockfile: $this->hasNpmLockfile,
            laravelPosture: $laravelPosture,
            requiredPackageNames: $this->requiredPackageNames,
            platformRequirements: $this->platformRequirements,
            providedPlatformNames: $this->providedPlatformNames,
            platformOverrideNames: $this->platformOverrideNames,
        );
    }
}
