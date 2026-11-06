<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Data\PackagistVersionData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\SkipReasonEnum;
use Qiick\Excubia\Services\ComposerConstraintService;
use Qiick\Excubia\Services\PackagistApiService;

/**
 * Reports direct Composer dependencies that have newer releases, like
 * `composer outdated --direct`: behind on a patch is info, behind on a minor
 * or a major is low. One warn row per package that is behind.
 *
 * It reads the same Packagist p2 metadata as `composer-abandoned` and
 * `composer-stale` through the shared `PackagistApiService`, so it sends no
 * request of its own when those checks already ran. Only stable releases
 * count, unless the locked version is itself a pre-release: then newer
 * pre-releases count too. `minimum-stability` is not read. A locked dev
 * branch (`dev-main`, `2.x-dev`) has nothing to compare and is left out.
 */
final class ComposerOutdatedCheck implements HealthCheckContract
{
    public function __construct(
        private readonly PackagistApiService $packagistApi,
        private readonly ComposerConstraintService $composerConstraint,
    ) {}

    public function id(): string
    {
        return 'composer-outdated';
    }

    public function name(): string
    {
        return 'Outdated packages';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $directPackages = array_values(array_filter(
            $projectSnapshot->composerPackages,
            static fn (LockedPackageData $package): bool => $package->isDirectDependency,
        ));
        $findings = FindingData::privatePackagesSkipped($this->id(), $configuration->privatePackageNames($directPackages), 'Packagist');

        foreach ($configuration->publicPackages($directPackages) as $package) {
            $lockedVersion = $this->composerConstraint->normalizedVersion($package->version);

            if ($lockedVersion === null || $this->composerConstraint->stability($lockedVersion) === 'dev') {
                continue;
            }

            $versions = $this->packagistApi->versionsIfAvailable($package->name);

            if ($versions === null) {
                return CheckResultData::skipped($this->id(), $this->name(), $package->name, 'Packagist metadata did not respond.', SkipReasonEnum::DataSourceFailure);
            }

            $newer = $this->composerConstraint->newerReleases(
                array_map(static fn (PackagistVersionData $version): string => $version->version, $versions),
                $lockedVersion,
            );

            if ($newer['latestMinor'] === null && $newer['latestMajor'] === null) {
                continue;
            }

            $findings[] = FindingData::outdatedPackage($this->id(), $package->name, $package->version, $newer, 'https://packagist.org/packages/'.$package->name);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }
}
