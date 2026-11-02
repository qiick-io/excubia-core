<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ComposerAdvisoryData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\ComposerConstraintService;
use Qiick\Excubia\Services\PackagistApiService;

/**
 * Fails when a Packagist advisory covers a locked Composer version.
 */
final class ComposerAdvisoriesCheck implements HealthCheckContract
{
    public function __construct(
        private readonly PackagistApiService $packagistApi,
        private readonly ComposerConstraintService $composerConstraint,
    ) {}

    public function id(): string
    {
        return 'composer-advisories';
    }

    public function name(): string
    {
        return 'Known vulnerabilities (Composer)';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $names = [];

        foreach ($projectSnapshot->composerPackages as $package) {
            $names[] = $package->name;
        }

        $advisories = $this->packagistApi->securityAdvisoriesIfAvailable($names);

        if ($advisories === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'composer', 'Packagist security advisories did not respond.');
        }

        $findings = [];

        foreach ($projectSnapshot->composerPackages as $package) {
            foreach ($advisories as $advisory) {
                if ($advisory->packageName !== $package->name) {
                    continue;
                }

                if (! $this->composerConstraint->isSatisfiedBy($package->version, $advisory->affectedVersions)) {
                    continue;
                }

                $findings[] = $this->finding($advisory);
            }
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    private function finding(ComposerAdvisoryData $advisory): FindingData
    {
        return new FindingData(
            checkId: $this->id(),
            severity: $this->severity($advisory->severity),
            status: FindingStatusEnum::Fail,
            subject: $advisory->packageName,
            message: 'Known vulnerability '.$advisory->advisoryId.'.',
            fixHint: 'Update '.$advisory->packageName.' to ≥ fixed version ('.$advisory->advisoryId.').',
            evidenceUrl: $advisory->link,
            advisoryId: $advisory->advisoryId,
        );
    }

    /**
     * Map a Packagist advisory severity string onto SeverityEnum (default medium).
     */
    private function severity(?string $value): SeverityEnum
    {
        if ($value === null) {
            return SeverityEnum::Medium;
        }

        return SeverityEnum::tryFrom(strtolower($value)) ?? SeverityEnum::Medium;
    }
}
