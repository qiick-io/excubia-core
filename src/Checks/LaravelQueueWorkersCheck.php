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
 * Tells whether queue workers run (#151), where `laravel-queue-backlog`
 * only shows the effect afterwards.
 *
 * With Laravel Horizon installed it reads Horizon's own status: `inactive`
 * fails at high, `paused` warns at medium, `running` passes. Without it, it
 * reads the worker heartbeat the service provider writes from the `Looping`
 * event and warns at medium for each queue of the default queue connection
 * that has pending jobs and no heartbeat newer than
 * `checks.laravel-queue-workers.staleAfterMinutes` (default 5). It is
 * skipped for the `sync` driver, for drivers whose backlog is not read, and
 * while no worker has ever reported. Connection and queue names only.
 */
final class LaravelQueueWorkersCheck implements HealthCheckContract
{
    private const SUPPORTED_DRIVERS = ['database', 'redis'];

    public function id(): string
    {
        return 'laravel-queue-workers';
    }

    public function name(): string
    {
        return 'Queue workers';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'queue', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->isHorizonInstalled) {
            return $this->horizonResult($posture);
        }

        $driver = $posture->driverNames['queue'] ?? null;

        if ($driver === 'sync') {
            return CheckResultData::skipped($this->id(), $this->name(), 'queue', 'The default queue connection uses the sync driver, which runs jobs without a worker.');
        }

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            return CheckResultData::skipped($this->id(), $this->name(), 'queue', 'Pending jobs are only read for the database and redis queue drivers; the default queue connection uses '.($driver ?? 'an unknown driver').'.');
        }

        if ($posture->queueBacklogs === null || $posture->queueWorkerHeartbeats === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'queue', 'The queue store or the cache store holding the worker heartbeat could not be read.');
        }

        $lastSeenAt = array_filter(array_column($posture->queueWorkerHeartbeats, 'lastSeenAt'));

        if ($lastSeenAt === []) {
            return CheckResultData::skipped($this->id(), $this->name(), 'queue', 'No queue worker has reported a heartbeat yet. Workers report once a minute; restart them after installing Excubia.');
        }

        $pendingCounts = array_column($posture->queueBacklogs, 'pendingCount', 'queue');
        $findings = [];

        foreach ($posture->queueWorkerHeartbeats as $heartbeat) {
            if (($pendingCounts[$heartbeat['queue']] ?? 0) < 1) {
                continue;
            }

            $minutesSinceLastSeen = $heartbeat['lastSeenAt'] === null
                ? null
                : intdiv(max(0, $posture->collectedAt->getTimestamp() - $heartbeat['lastSeenAt']->getTimestamp()), 60);

            if ($minutesSinceLastSeen !== null && $minutesSinceLastSeen <= $configuration->queueWorkersStaleAfterMinutes) {
                continue;
            }

            $findings[] = new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::Medium,
                status: FindingStatusEnum::Warn,
                subject: $heartbeat['queue'],
                message: $minutesSinceLastSeen === null
                    ? 'Queue '.$heartbeat['queue'].' has pending jobs and no worker has ever reported on it.'
                    : 'Queue '.$heartbeat['queue'].' has pending jobs and no worker has reported on it for '.$minutesSinceLastSeen.' minutes.',
                fixHint: 'Start or restart a worker for this queue (php artisan queue:work '.$heartbeat['connection'].' --queue='.$heartbeat['queue'].') and keep it running with Supervisor or your platform.',
                evidenceUrl: 'https://laravel.com/docs/queues#supervisor-configuration',
                details: [
                    'connection' => $heartbeat['connection'],
                    'queue' => $heartbeat['queue'],
                    'minutesSinceLastSeen' => $minutesSinceLastSeen,
                ],
            );
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    private function horizonResult(LaravelPostureData $posture): CheckResultData
    {
        $horizon = $posture->horizonStatus;

        if ($horizon === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'horizon', 'The status of Laravel Horizon could not be read.');
        }

        if ($horizon['status'] === 'running') {
            return CheckResultData::fromFindings($this->id(), $this->name(), []);
        }

        $isPaused = $horizon['status'] === 'paused';

        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData(
                checkId: $this->id(),
                severity: $isPaused ? SeverityEnum::Medium : SeverityEnum::High,
                status: $isPaused ? FindingStatusEnum::Warn : FindingStatusEnum::Fail,
                subject: 'horizon',
                message: $isPaused ? 'Laravel Horizon is paused: queued jobs are not processed.' : 'Laravel Horizon is inactive: no master supervisor is running.',
                fixHint: $isPaused ? 'Run php artisan horizon:continue.' : 'Start php artisan horizon and keep it running with Supervisor or your platform.',
                evidenceUrl: 'https://laravel.com/docs/horizon#running-horizon',
                details: [
                    'status' => $horizon['status'],
                    'processes' => $horizon['processes'],
                    'supervisors' => $horizon['supervisors'],
                ],
            ),
        ]);
    }
}
