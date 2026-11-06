<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\DependencyLicensesConfigurationData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\LicenseInventoryData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\LicenseVerdictEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Enums\SkipReasonEnum;
use Qiick\Excubia\Services\SpdxExpressionService;

/**
 * License inventory of the locked Composer and npm packages (#134).
 *
 * The licenses come from the lockfiles, so the check sends nothing and
 * never skips. One row per package whose license needs a look: denied by
 * `checks.dependency-licenses.denied` fails at high, strong copyleft
 * (GPL, AGPL, SSPL, EUPL, …) warns at medium, a missing or unknown license
 * warns at low. Allowed and known licenses add no row. Development
 * dependencies are left out unless `includeDev` is true. The counts of
 * every inspected package become the report's `licenses` inventory.
 *
 * `yarn.lock` and `pnpm-lock.yaml` record no licenses (#21): their npm
 * packages without one are left out of the rows and the inventory, and one
 * `skipped` row (`not-applicable`, subject the lockfile) counts them.
 */
final class DependencyLicensesCheck implements HealthCheckContract
{
    private const EVIDENCE_URL_PREFIXES = [
        'composer' => 'https://packagist.org/packages/',
        'npm' => 'https://www.npmjs.com/package/',
    ];

    public function __construct(private readonly SpdxExpressionService $spdxExpressions) {}

    public function id(): string
    {
        return 'dependency-licenses';
    }

    public function name(): string
    {
        return 'Dependency licenses';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $policy = $configuration->dependencyLicenses;
        $findings = [];
        $ecosystems = [];

        foreach (['composer' => $projectSnapshot->composerPackages, 'npm' => $projectSnapshot->npmPackages] as $ecosystem => $lockedPackages) {
            $packages = $this->inspectedPackages($lockedPackages, $policy);

            if ($ecosystem === 'npm' && ! $projectSnapshot->hasJavascriptLicenseData()) {
                $licensed = array_values(array_filter($packages, static fn (LockedPackageData $package): bool => $package->licenses !== []));
                $findings = [...$findings, ...$this->withoutLicenseData(count($packages) - count($licensed), (string) $projectSnapshot->javascriptLockfileName())];
                $packages = $licensed;
            }

            $ecosystems[$ecosystem] = $this->inventory($packages);

            foreach ($packages as $package) {
                $finding = $this->finding($package, $ecosystem, $this->spdxExpressions->verdict($package->licenses, $policy), $configuration->isPrivatePackage($package));

                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings, licenseInventory: new LicenseInventoryData($ecosystems));
    }

    /**
     * Unique name and version pairs, without development dependencies
     * unless `includeDev` is on. npm can lock one version in several paths.
     *
     * @param  list<LockedPackageData>  $packages
     * @return list<LockedPackageData>
     */
    private function inspectedPackages(array $packages, DependencyLicensesConfigurationData $policy): array
    {
        $inspected = [];

        foreach ($packages as $package) {
            if ($package->isDevelopmentDependency && ! $policy->isDevelopmentDependencyIncluded) {
                continue;
            }

            $inspected[$package->name."\0".$package->version] ??= $package;
        }

        return array_values($inspected);
    }

    /**
     * The summary row for npm packages whose lockfile records no license,
     * or no row when there are none.
     *
     * @return list<FindingData>
     */
    private function withoutLicenseData(int $count, string $lockfile): array
    {
        if ($count === 0) {
            return [];
        }

        return [new FindingData(
            checkId: $this->id(),
            severity: SeverityEnum::Info,
            status: FindingStatusEnum::Skipped,
            subject: $lockfile,
            message: $count.' '.($count === 1 ? 'package' : 'packages').' from '.$lockfile.' '.($count === 1 ? 'has' : 'have').' no license data: the lockfile does not record licenses.',
            fixHint: '',
            evidenceUrl: null,
            skipReason: SkipReasonEnum::NotApplicable,
        )];
    }

    /**
     * @param  list<LockedPackageData>  $packages
     * @return array{packageCount: int, byLicense: array<string, int>}
     */
    private function inventory(array $packages): array
    {
        $counts = [];

        foreach ($packages as $package) {
            $licenseIds = [];

            foreach ($package->licenses as $expression) {
                foreach ($this->spdxExpressions->licenseIds($expression) as $licenseId) {
                    $licenseIds[$licenseId] = true;
                }
            }

            if ($licenseIds === []) {
                $licenseIds[LicenseInventoryData::NO_LICENSE_KEY] = true;
            }

            foreach (array_keys($licenseIds) as $licenseId) {
                $counts[(string) $licenseId] = ($counts[(string) $licenseId] ?? 0) + 1;
            }
        }

        ksort($counts, SORT_STRING);

        return ['packageCount' => count($packages), 'byLicense' => $counts];
    }

    private function finding(LockedPackageData $package, string $ecosystem, LicenseVerdictEnum $verdict, bool $isPrivate): ?FindingData
    {
        $declared = $package->licenses === [] ? 'none declared' : implode(' OR ', $package->licenses);

        [$severity, $status, $message, $fixHint] = match ($verdict) {
            LicenseVerdictEnum::Permitted => [null, null, '', ''],
            LicenseVerdictEnum::Denied => [
                SeverityEnum::High,
                FindingStatusEnum::Fail,
                'License denied by checks.dependency-licenses.denied: '.$declared.'.',
                'Replace the package, or remove the license from checks.dependency-licenses.denied after a legal review.',
            ],
            LicenseVerdictEnum::StrongCopyleft => [
                SeverityEnum::Medium,
                FindingStatusEnum::Warn,
                'Strong copyleft license: '.$declared.'.',
                'Check that the license fits how you distribute the app, then add it to checks.dependency-licenses.allowed or replace the package.',
            ],
            LicenseVerdictEnum::Unknown => [
                SeverityEnum::Low,
                FindingStatusEnum::Warn,
                $package->licenses === [] ? 'No license declared.' : 'Unknown license: '.$declared.'.',
                'Check the package license, then add it to checks.dependency-licenses.allowed.',
            ],
        };

        if ($severity === null || $status === null) {
            return null;
        }

        return new FindingData(
            checkId: $this->id(),
            severity: $severity,
            status: $status,
            subject: $package->name,
            message: $message,
            fixHint: $fixHint,
            evidenceUrl: $isPrivate ? null : self::EVIDENCE_URL_PREFIXES[$ecosystem].$package->name,
            details: [
                'package' => $package->name,
                'lockedVersion' => $package->version,
                'licenses' => $package->licenses,
                'ecosystem' => $ecosystem,
            ],
        );
    }
}
