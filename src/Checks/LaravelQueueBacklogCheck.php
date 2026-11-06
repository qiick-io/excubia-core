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
 * Warns at medium for each queue of the default queue connection whose
 * oldest pending job waits longer than
 * `checks.laravel-queue-backlog.maxOldestAgeMinutes` (default 15) or that
 * holds more than `maxPendingJobs` (default 1000). Only the `database` and
 * `redis` drivers are read; others are skipped. Counts and ages only.
 */
final class LaravelQueueBacklogCheck implements HealthCheckContract
{
    private const SUPPORTED_DRIVERS = ['database', 'redis'];

    public function id(): string
    {
        return 'laravel-queue-backlog';
    }

    public function name(): string
    {
        return 'Queue backlog';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'queue', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        $driver = $posture->driverNames['queue'] ?? null;

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            return CheckResultData::skipped($this->id(), $this->name(), 'queue', 'Only the database and redis queue drivers are read; the default queue connection uses '.($driver ?? 'an unknown driver').'.');
        }

        if ($posture->queueBacklogs === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'queue', 'The queue store of the default queue connection could not be read.');
        }

        $findings = [];

        foreach ($posture->queueBacklogs as $backlog) {
            $isOld = $backlog['oldestAgeMinutes'] !== null && $backlog['oldestAgeMinutes'] > $configuration->queueBacklogMaxOldestAgeMinutes;
            $isLong = $backlog['pendingCount'] > $configuration->queueBacklogMaxPendingJobs;

            if (! $isOld && ! $isLong) {
                continue;
            }

            $message = 'Queue '.$backlog['queue'].' holds '.$backlog['pendingCount'].' pending '.($backlog['pendingCount'] === 1 ? 'job' : 'jobs').'.';

            if ($backlog['oldestAgeMinutes'] !== null) {
                $message .= ' The oldest has waited '.$backlog['oldestAgeMinutes'].' minutes.';
            }

            $findings[] = new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::Medium,
                status: FindingStatusEnum::Warn,
                subject: $backlog['queue'],
                message: $message,
                fixHint: 'Check that queue workers run for this queue (php artisan queue:work, Horizon or Supervisor) and add workers if they cannot keep up.',
                evidenceUrl: 'https://laravel.com/docs/queues#running-the-queue-worker',
                details: [
                    'queue' => $backlog['queue'],
                    'pendingCount' => $backlog['pendingCount'],
                    'oldestAgeMinutes' => $backlog['oldestAgeMinutes'],
                ],
            );
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }
}
