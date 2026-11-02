<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\PackagistVersionData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\ComposerConstraintService;
use Qiick\Excubia\Services\PackagistApiService;

/**
 * Asks whether each direct dependency's latest release allows the next
 * Laravel major. That major is the locked laravel/framework major plus one.
 */
final class LaravelNextMajorBlockerCheck implements HealthCheckContract
{
    public function __construct(
        private readonly PackagistApiService $packagistApi,
        private readonly ComposerConstraintService $composerConstraint,
    ) {}

    public function id(): string
    {
        return 'laravel-next-blocker';
    }

    public function name(): string
    {
        return 'Next-major blockers';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $locked = $projectSnapshot->laravelFrameworkVersion;

        if ($locked === null || preg_match('/^v?(\d+)/i', $locked, $matches) !== 1) {
            return CheckResultData::skipped($this->id(), $this->name(), 'laravel/framework', 'laravel/framework is not locked.');
        }

        $nextMajor = (int) $matches[1] + 1;
        $target = $nextMajor.'.0.0';
        $findings = [];

        foreach ($projectSnapshot->composerPackages as $package) {
            if (! $package->isDirectDependency || $package->name === 'laravel/framework') {
                continue;
            }

            $versions = $this->packagistApi->versionsIfAvailable($package->name);

            if ($versions === null) {
                return CheckResultData::skipped($this->id(), $this->name(), $package->name, 'Packagist metadata did not respond.');
            }

            if (! $this->dependsOnLaravel($versions)) {
                continue;
            }

            $stable = $this->latestStable($versions);
            $doesStableAllowTarget = $stable !== null && $this->isCompatibleWith($stable, $target);
            $preReleaseAllows = $this->preReleaseAllows($versions, $target);

            if ($doesStableAllowTarget) {
                continue;
            }

            if ($preReleaseAllows) {
                $findings[] = $this->finding($package->name, FindingStatusEnum::Warn, SeverityEnum::Low, $nextMajor);

                continue;
            }

            $findings[] = $this->finding($package->name, FindingStatusEnum::Fail, SeverityEnum::Medium, $nextMajor);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    private function finding(string $packageName, FindingStatusEnum $status, SeverityEnum $severity, int $nextMajor): FindingData
    {
        return new FindingData(
            checkId: $this->id(),
            severity: $severity,
            status: $status,
            subject: $packageName,
            message: 'Latest release does not allow Laravel '.$nextMajor.'.',
            fixHint: 'No release supports Laravel '.$nextMajor.' yet; watch / replace / fork.',
            evidenceUrl: 'https://packagist.org/packages/'.$packageName,
        );
    }

    /**
     * True when any published version requires laravel/framework or illuminate/*.
     *
     * @param  list<PackagistVersionData>  $versions
     */
    private function dependsOnLaravel(array $versions): bool
    {
        foreach ($versions as $version) {
            foreach ($version->requirements as $name => $constraint) {
                if ($name === 'laravel/framework' || str_starts_with($name, 'illuminate/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Newest stable (non pre-release) Packagist version, or null when none.
     *
     * @param  list<PackagistVersionData>  $versions
     */
    private function latestStable(array $versions): ?PackagistVersionData
    {
        $latest = null;

        foreach ($versions as $version) {
            if ($this->isPreRelease($version->version)) {
                continue;
            }

            if ($latest === null || version_compare(ltrim($version->version, 'vV'), ltrim($latest->version, 'vV'), '>')) {
                $latest = $version;
            }
        }

        return $latest;
    }

    /**
     * True when any pre-release version's Laravel constraints accept `$target`.
     *
     * @param  list<PackagistVersionData>  $versions
     */
    private function preReleaseAllows(array $versions, string $target): bool
    {
        foreach ($versions as $version) {
            if (! $this->isPreRelease($version->version)) {
                continue;
            }

            if ($this->isCompatibleWith($version, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when every laravel/framework and illuminate/* requirement on the
     * version is satisfied by `$target`.
     */
    private function isCompatibleWith(PackagistVersionData $version, string $target): bool
    {
        foreach ($version->requirements as $name => $constraint) {
            if ($name !== 'laravel/framework' && ! str_starts_with($name, 'illuminate/')) {
                continue;
            }

            if (! $this->composerConstraint->isSatisfiedBy($target, $constraint)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Packagist pre-release or branch alias (`dev-*` or any `-` stability suffix).
     */
    private function isPreRelease(string $version): bool
    {
        return str_starts_with($version, 'dev-') || str_contains($version, '-');
    }
}
