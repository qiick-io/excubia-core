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
use Qiick\Excubia\Services\DatabaseStatisticsService;

/**
 * Counts the open connections of the default database connection (#169):
 * `Threads_connected` on MySQL and MariaDB (the whole server), the
 * `pg_stat_activity` rows of the database on PostgreSQL. Warns at medium
 * above `checks.laravel-database-connections.warnAt` (default 50), fails at
 * high above `failAt` (default 100). Other drivers are skipped. Only the
 * connection name and the count are reported.
 */
final class LaravelDatabaseConnectionsCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'laravel-database-connections';
    }

    public function name(): string
    {
        return 'Database connections';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'database', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        $connection = $posture->defaultDatabaseConnectionName;

        if ($connection === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'database', 'No default database connection is configured.');
        }

        if (! in_array($posture->defaultDatabaseDriverName, DatabaseStatisticsService::CONNECTION_COUNT_DRIVERS, true)) {
            return CheckResultData::skipped($this->id(), $this->name(), $connection, 'The default database connection uses '.($posture->defaultDatabaseDriverName ?? 'an unknown driver').': open connections are counted on MySQL, MariaDB and PostgreSQL only.');
        }

        $count = $posture->databaseConnectionCount;

        if ($count === null) {
            return CheckResultData::skipped($this->id(), $this->name(), $connection, 'The open connections of the default database connection could not be counted.');
        }

        if ($count <= $configuration->databaseConnectionsWarnAt) {
            return CheckResultData::fromFindings($this->id(), $this->name(), []);
        }

        $isOverLimit = $count > $configuration->databaseConnectionsFailAt;

        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData(
                checkId: $this->id(),
                severity: $isOverLimit ? SeverityEnum::High : SeverityEnum::Medium,
                status: $isOverLimit ? FindingStatusEnum::Fail : FindingStatusEnum::Warn,
                subject: $connection,
                message: 'The database of connection '.$connection.' has '.$count.' open connections (more than '.($isOverLimit ? $configuration->databaseConnectionsFailAt : $configuration->databaseConnectionsWarnAt).').',
                fixHint: 'Look for leaked or idle connections (long-running workers, missing pooling) or raise the server limit and checks.laravel-database-connections.',
                evidenceUrl: 'https://laravel.com/docs/database#configuration',
                details: ['connection' => $connection, 'connectionCount' => $count],
            ),
        ]);
    }
}
