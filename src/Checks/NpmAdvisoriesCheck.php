<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\OsvApiService;

/**
 * Fails when OSV reports a vulnerability for a locked npm version.
 * Severity comes from a numeric CVSS score when one is present, otherwise medium.
 */
final class NpmAdvisoriesCheck implements HealthCheckContract
{
    public function __construct(private readonly OsvApiService $osvApi) {}

    public function id(): string
    {
        return 'npm-advisories';
    }

    public function name(): string
    {
        return 'Known vulnerabilities (npm)';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        if (! $projectSnapshot->hasNpmLockfile) {
            return CheckResultData::skipped($this->id(), $this->name(), 'npm', 'package-lock.json is not present.');
        }

        $packages = [];

        foreach ($projectSnapshot->npmPackages as $package) {
            $packages[] = ['name' => $package->name, 'version' => $package->version];
        }

        $vulnerabilities = $this->osvApi->vulnerabilitiesIfAvailable($packages);

        if ($vulnerabilities === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'npm', 'OSV.dev did not respond.');
        }

        $findings = [];

        foreach ($vulnerabilities as $vulnerability) {
            $findings[] = new FindingData(
                checkId: $this->id(),
                severity: $this->severity($vulnerability->severity),
                status: FindingStatusEnum::Fail,
                subject: $vulnerability->packageName,
                message: 'Known vulnerability '.$vulnerability->id.'.',
                fixHint: 'Update '.$vulnerability->packageName.' to ≥ fixed version.',
                evidenceUrl: 'https://osv.dev/vulnerability/'.$vulnerability->id,
                advisoryId: $vulnerability->id,
            );
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    /**
     * Map a numeric CVSS score string onto SeverityEnum; non-numeric → medium.
     */
    private function severity(?string $value): SeverityEnum
    {
        if ($value === null || preg_match('/^(\d+(?:\.\d+)?)$/', $value, $matches) !== 1) {
            return SeverityEnum::Medium;
        }

        $score = (float) $matches[1];

        if ($score >= 9.0) {
            return SeverityEnum::Critical;
        }

        if ($score >= 7.0) {
            return SeverityEnum::High;
        }

        if ($score >= 4.0) {
            return SeverityEnum::Medium;
        }

        return SeverityEnum::Low;
    }
}
