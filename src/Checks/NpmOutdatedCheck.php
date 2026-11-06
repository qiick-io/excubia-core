<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\SkipReasonEnum;
use Qiick\Excubia\Services\ComposerConstraintService;
use Qiick\Excubia\Services\NpmRegistryService;

/**
 * Reports direct npm dependencies that have newer releases (#153), the npm
 * sibling of `composer-outdated` with the same severities and `details`:
 * behind on a patch is info, behind on a minor or a major is low.
 *
 * Direct dependencies are the `package.json` `dependencies` and
 * `optionalDependencies`, plus `devDependencies` unless `--no-dev`, with
 * the versions of the JavaScript lockfile the snapshot read (#21). Each
 * public one costs one request to the npm registry carrying its name only;
 * private packages (#108) are never sent. Versions are compared with
 * Composer's version rules, which read npm semver; pre-releases count only
 * when the locked version is a pre-release, and a stable release newer than
 * the `latest` dist-tag is ignored. A version Composer cannot read (a
 * pre-release tag such as `-canary.abc`) is left out.
 */
final class NpmOutdatedCheck implements HealthCheckContract
{
    public function __construct(
        private readonly NpmRegistryService $npmRegistry,
        private readonly ComposerConstraintService $composerConstraint,
    ) {}

    public function id(): string
    {
        return 'npm-outdated';
    }

    public function name(): string
    {
        return 'Outdated packages (npm)';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        if ($projectSnapshot->javascriptLockfileName() === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'npm', 'No JavaScript lockfile is present (pnpm-lock.yaml, yarn.lock or package-lock.json).');
        }

        $directPackages = $this->directPackages($projectSnapshot, $configuration);
        $privateNames = $configuration->privatePackageNames($directPackages);
        $findings = FindingData::privatePackagesSkipped($this->id(), $privateNames, 'the npm registry');

        foreach ($this->lockedVersions($configuration->publicPackages($directPackages), $privateNames) as [$name, $version, $lockedVersion]) {
            $metadata = $this->npmRegistry->metadataIfAvailable($name);

            if ($metadata === null) {
                return CheckResultData::skipped($this->id(), $this->name(), $name, 'The npm registry did not respond.', SkipReasonEnum::DataSourceFailure);
            }

            $newer = $this->composerConstraint->newerReleases($metadata->versions, $lockedVersion, $metadata->latestVersion);

            if ($newer['latestMinor'] === null && $newer['latestMajor'] === null) {
                continue;
            }

            $findings[] = FindingData::outdatedPackage($this->id(), $name, $version, $newer, 'https://www.npmjs.com/package/'.$name);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    /**
     * Locked packages named in `package.json`, without the dev-only ones
     * when development dependencies are excluded.
     *
     * @return list<LockedPackageData>
     */
    private function directPackages(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): array
    {
        $excludedNames = $configuration->isDevelopmentDependencyIncluded ? [] : array_fill_keys($projectSnapshot->npmDevelopmentPackageNames, true);

        return array_values(array_filter(
            $projectSnapshot->npmPackages,
            static fn (LockedPackageData $package): bool => $package->isDirectDependency && ! isset($excludedNames[$package->name]),
        ));
    }

    /**
     * One locked version per public package name, as written and normalized.
     * A name with a private copy is left out entirely, and locked dev or
     * unreadable versions are skipped.
     *
     * ponytail: a lockfile can hold a direct name at several versions (a
     * nested copy for another dependency) and the snapshot does not keep
     * which one is the project's; the highest is used, so a package is never
     * reported behind because of an older nested copy. Read the lockfile's
     * top-level entry (npm path, pnpm importers, Yarn specifier) if that
     * proves too lenient.
     *
     * @param  list<LockedPackageData>  $packages
     * @param  list<string>  $privateNames
     * @return list<array{string, string, string}> name, version as written, normalized version
     */
    private function lockedVersions(array $packages, array $privateNames): array
    {
        $lockedVersions = [];

        foreach ($packages as $package) {
            $normalized = $this->composerConstraint->normalizedVersion($package->version);

            if ($normalized === null || $this->composerConstraint->stability($normalized) === 'dev' || in_array($package->name, $privateNames, true)) {
                continue;
            }

            // The NUL prefix keeps a numeric package name a string key.
            $key = "\0".$package->name;
            $current = $lockedVersions[$key] ?? null;

            if ($current === null || $this->composerConstraint->isNewer($normalized, $current[2])) {
                $lockedVersions[$key] = [$package->name, $package->version, $normalized];
            }
        }

        return array_values($lockedVersions);
    }
}
