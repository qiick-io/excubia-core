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
use Qiick\Excubia\Services\PackagistApiService;

/**
 * Flags Packagist packages marked abandoned. A direct dependency fails
 * at high. A transitive dependency warns at medium.
 */
final class ComposerAbandonedCheck implements HealthCheckContract
{
    public function __construct(private readonly PackagistApiService $packagistApi) {}

    public function id(): string
    {
        return 'composer-abandoned';
    }

    public function name(): string
    {
        return 'Abandoned packages';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $findings = [];

        foreach ($projectSnapshot->composerPackages as $package) {
            $versions = $this->packagistApi->versionsIfAvailable($package->name);

            if ($versions === null) {
                return CheckResultData::skipped($this->id(), $this->name(), $package->name, 'Packagist metadata did not respond.');
            }

            $abandoned = $this->abandonedFlag($versions);

            if ($abandoned === null || $abandoned === false) {
                continue;
            }

            $findings[] = new FindingData(
                checkId: $this->id(),
                severity: $package->isDirectDependency ? SeverityEnum::High : SeverityEnum::Medium,
                status: $package->isDirectDependency ? FindingStatusEnum::Fail : FindingStatusEnum::Warn,
                subject: $package->name,
                message: $package->isDirectDependency
                    ? 'Abandoned direct dependency.'
                    : 'Abandoned transitive dependency.',
                fixHint: is_string($abandoned)
                    ? 'Replace with '.$abandoned.'.'
                    : 'no replacement suggested',
                evidenceUrl: 'https://packagist.org/packages/'.$package->name,
            );
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    /**
     * First non-false abandoned flag across versions: replacement name, true, or null.
     *
     * @param  list<PackagistVersionData>  $versions
     */
    private function abandonedFlag(array $versions): string|bool|null
    {
        foreach ($versions as $version) {
            if (is_string($version->abandoned) && $version->abandoned !== '') {
                return $version->abandoned;
            }

            if ($version->abandoned === true) {
                return true;
            }
        }

        return null;
    }
}
