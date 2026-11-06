<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\LaravelPostureData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;

/**
 * Checks the disk holding `storage/`: warn at medium from
 * `checks.laravel-disk-space.warnAtPercentUsed` (default 80), fail at high
 * from `failAtPercentUsed` (default 90). Only the percentage is reported.
 */
final class LaravelDiskSpaceCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'laravel-disk-space';
    }

    public function name(): string
    {
        return 'Disk space';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'disk', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        $usedPercent = $posture->diskUsedPercent;

        if ($usedPercent === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'disk', 'The disk holding storage/ could not be measured.');
        }

        if ($usedPercent < $configuration->diskSpaceWarnAtPercentUsed) {
            return CheckResultData::fromFindings($this->id(), $this->name(), []);
        }

        $isFull = $usedPercent >= $configuration->diskSpaceFailAtPercentUsed;

        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData(
                checkId: $this->id(),
                severity: $isFull ? SeverityEnum::High : SeverityEnum::Medium,
                status: $isFull ? FindingStatusEnum::Fail : FindingStatusEnum::Warn,
                subject: 'disk',
                message: 'The disk holding storage/ is '.$usedPercent.' % full.',
                fixHint: 'Free space (old logs, cache and session files, backups) or grow the disk.',
                evidenceUrl: null,
                details: ['usedPercent' => $usedPercent],
            ),
        ]);
    }
}
