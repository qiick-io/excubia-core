<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

/**
 * Manifests and lockfiles reduced to the values the checks are allowed to see.
 *
 * Paths, file contents, and hostnames are not stored. Only versions and
 * package names leave this object toward a data source. `laravelPosture`
 * is set only by the Artisan commands, which boot the app; the standalone
 * CLI leaves it null and the posture checks skip. `environmentKeys` holds
 * key names only (`.env.example` and `config/*.php`, never `.env`); it is
 * null for a snapshot not read from a project directory.
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
     * @param  list<string>  $npmDevelopmentPackageNames  names only in `package.json` `devDependencies` (not in `dependencies` or `optionalDependencies`)
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
        public ?EnvironmentKeysData $environmentKeys = null,
        public ?string $javascriptLockfile = null,
        public array $npmDevelopmentPackageNames = [],
    ) {}

    /**
     * The JavaScript lockfile the npm packages came from. A snapshot built
     * by hand (the web scanner) with npm packages but no file name came
     * from `package-lock.json`, the only format it accepts.
     */
    public function javascriptLockfileName(): ?string
    {
        if (! $this->hasNpmLockfile) {
            return null;
        }

        return $this->javascriptLockfile ?? 'package-lock.json';
    }

    /**
     * False for `yarn.lock` and `pnpm-lock.yaml`, which record no licenses.
     */
    public function hasJavascriptLicenseData(): bool
    {
        return ! in_array($this->javascriptLockfileName(), ['yarn.lock', 'pnpm-lock.yaml'], true);
    }

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
            environmentKeys: $this->environmentKeys,
            javascriptLockfile: $this->javascriptLockfile,
            npmDevelopmentPackageNames: $this->npmDevelopmentPackageNames,
        );
    }
}
