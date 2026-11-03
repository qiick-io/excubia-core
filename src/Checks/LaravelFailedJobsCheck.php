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
 * Warns when the failed job store holds jobs: medium, or high once the
 * oldest is at least `checks.laravel-failed-jobs.highSeverityAfterHours`
 * old (default 24). Only the count and the age are reported, never payloads.
 */
final class LaravelFailedJobsCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'laravel-failed-jobs';
    }

    public function name(): string
    {
        return 'Failed jobs';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'failed-jobs', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->failedJobCount === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'failed-jobs', 'There is no failed job store, or it could not be read.');
        }

        if ($posture->failedJobCount === 0) {
            return CheckResultData::fromFindings($this->id(), $this->name(), []);
        }

        $ageHours = null;

        if ($posture->oldestFailedJobAt !== null) {
            $ageHours = intdiv(max(0, $posture->collectedAt->getTimestamp() - $posture->oldestFailedJobAt->getTimestamp()), 3600);
        }

        $isOld = $ageHours !== null && $ageHours >= $configuration->failedJobHighSeverityAfterHours;
        $count = $posture->failedJobCount;
        $message = $count.' failed '.($count === 1 ? 'job' : 'jobs').'.';

        if ($ageHours !== null) {
            $message .= ' The oldest failed '.$ageHours.' '.($ageHours === 1 ? 'hour' : 'hours').' ago.';
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData(
                checkId: $this->id(),
                severity: $isOld ? SeverityEnum::High : SeverityEnum::Medium,
                status: FindingStatusEnum::Warn,
                subject: 'failed-jobs',
                message: $message,
                fixHint: 'Inspect them with php artisan queue:failed, then retry (queue:retry) or remove them (queue:forget, queue:flush).',
                evidenceUrl: 'https://laravel.com/docs/queues#dealing-with-failed-jobs',
                details: ['count' => $count, 'oldestAgeHours' => $ageHours],
            ),
        ]);
    }
}
