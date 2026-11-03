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
 * Warns at medium when the scheduler has not run `excubia:heartbeat` (which
 * the service provider schedules every minute) for more than
 * `checks.laravel-scheduler-heartbeat.staleAfterMinutes` (default 10), or
 * has never run it.
 */
final class LaravelSchedulerHeartbeatCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'laravel-scheduler-heartbeat';
    }

    public function name(): string
    {
        return 'Scheduler heartbeat';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'scheduler', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if (! $posture->isSchedulerHeartbeatReadable) {
            return CheckResultData::skipped($this->id(), $this->name(), 'scheduler', 'The cache store that holds the scheduler heartbeat could not be read.');
        }

        if ($posture->schedulerHeartbeatAt === null) {
            return $this->warned('The scheduler has never run excubia:heartbeat.', null);
        }

        $ageMinutes = intdiv(max(0, $posture->collectedAt->getTimestamp() - $posture->schedulerHeartbeatAt->getTimestamp()), 60);

        if ($ageMinutes <= $configuration->schedulerHeartbeatStaleAfterMinutes) {
            return CheckResultData::fromFindings($this->id(), $this->name(), []);
        }

        return $this->warned('The scheduler last ran '.$ageMinutes.' minutes ago.', $ageMinutes);
    }

    /**
     * `$minutesSinceLastRun` is null when the heartbeat was never written.
     */
    private function warned(string $message, ?int $minutesSinceLastRun): CheckResultData
    {
        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::Medium,
                status: FindingStatusEnum::Warn,
                subject: 'scheduler',
                message: $message,
                fixHint: 'Run php artisan schedule:run every minute (cron) or keep php artisan schedule:work running.',
                evidenceUrl: 'https://laravel.com/docs/scheduling#running-the-scheduler',
                details: ['minutesSinceLastRun' => $minutesSinceLastRun],
            ),
        ]);
    }
}
