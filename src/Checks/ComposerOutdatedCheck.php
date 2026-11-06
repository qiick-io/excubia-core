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
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
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

            $newer = $this->newerReleases($versions, $lockedVersion);

            if ($newer['latestMinor'] === null && $newer['latestMajor'] === null) {
                continue;
            }

            $findings[] = $this->finding($package, $lockedVersion, $newer);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    /**
     * The newest release in the locked major.minor, in the locked major, and
     * in a later major, each only when it is newer than the locked version.
     * Values are the versions as Packagist prints them (`v7.4.1`).
     *
     * @param  list<PackagistVersionData>  $versions
     * @return array{latestPatch: string|null, latestMinor: string|null, latestMajor: string|null}
     */
    private function newerReleases(array $versions, string $lockedVersion): array
    {
        $isPreReleaseAllowed = $this->composerConstraint->stability($lockedVersion) !== 'stable';
        [$lockedMajor, $lockedMinor] = $this->majorAndMinor($lockedVersion);
        /** @var array{latestPatch: array{string, string}|null, latestMinor: array{string, string}|null, latestMajor: array{string, string}|null} $newest */
        $newest = ['latestPatch' => null, 'latestMinor' => null, 'latestMajor' => null];

        foreach ($versions as $version) {
            $normalized = $this->composerConstraint->normalizedVersion($version->version);

            if ($normalized === null || ! $this->composerConstraint->isNewer($normalized, $lockedVersion)) {
                continue;
            }

            $stability = $this->composerConstraint->stability($normalized);

            if ($stability === 'dev' || ($stability !== 'stable' && ! $isPreReleaseAllowed)) {
                continue;
            }

            [$major, $minor] = $this->majorAndMinor($normalized);
            $slots = $major > $lockedMajor ? ['latestMajor'] : ($minor === $lockedMinor ? ['latestPatch', 'latestMinor'] : ['latestMinor']);

            foreach ($slots as $slot) {
                if ($newest[$slot] === null || $this->composerConstraint->isNewer($normalized, $newest[$slot][0])) {
                    $newest[$slot] = [$normalized, $version->version];
                }
            }
        }

        return array_map(static fn (?array $release): ?string => $release === null ? null : $release[1], $newest);
    }

    /**
     * @param  array{latestPatch: string|null, latestMinor: string|null, latestMajor: string|null}  $newer
     */
    private function finding(LockedPackageData $package, string $lockedVersion, array $newer): FindingData
    {
        $isMajorBehind = $newer['latestMajor'] !== null;
        $isMinorBehind = $newer['latestMinor'] !== null && $newer['latestMinor'] !== $newer['latestPatch'];

        if ($isMajorBehind) {
            $message = 'A new major is available: '.$newer['latestMajor'].'.';
            $fixHint = $newer['latestMinor'] === null
                ? 'Plan the upgrade to '.$newer['latestMajor'].'.'
                : 'Update to '.$newer['latestMinor'].', then plan the upgrade to '.$newer['latestMajor'].'.';
        } else {
            $message = ($isMinorBehind ? 'Behind on a minor' : 'Behind on a patch').': '.$newer['latestMinor'].' is available.';
            $fixHint = 'Update to '.$newer['latestMinor'].'.';
        }

        return new FindingData(
            checkId: $this->id(),
            severity: $isMajorBehind || $isMinorBehind ? SeverityEnum::Low : SeverityEnum::Info,
            status: FindingStatusEnum::Warn,
            subject: $package->name,
            message: $message,
            fixHint: $fixHint,
            evidenceUrl: 'https://packagist.org/packages/'.$package->name,
            details: [
                'package' => $package->name,
                'lockedVersion' => $package->version,
                'latestPatch' => $newer['latestPatch'],
                'latestMinor' => $newer['latestMinor'],
                'latestMajor' => $newer['latestMajor'],
                'isMajorBehind' => $isMajorBehind,
            ],
        );
    }

    /**
     * @return array{int, int}
     */
    private function majorAndMinor(string $normalizedVersion): array
    {
        $parts = explode('.', $normalizedVersion);

        return [(int) $parts[0], (int) ($parts[1] ?? 0)];
    }
}
