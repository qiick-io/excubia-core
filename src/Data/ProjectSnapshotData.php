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
        );
    }
}
