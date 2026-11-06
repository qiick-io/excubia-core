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
 * Reports the size of the default database in MB and its five largest
 * tables (#169): MySQL and MariaDB from `information_schema`, PostgreSQL from
 * `pg_database_size`, SQLite from the size of the file. Without
 * `checks.laravel-database-size.warnAtMb` (the default) the size is a pass
 * row at info; above it the row warns at medium. Other drivers are skipped.
 * Only the connection name, megabytes and table names are reported, never
 * the database name, host or file path.
 */
final class LaravelDatabaseSizeCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'laravel-database-size';
    }

    public function name(): string
    {
        return 'Database size';
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

        if (! in_array($posture->defaultDatabaseDriverName, DatabaseStatisticsService::SIZE_DRIVERS, true)) {
            return CheckResultData::skipped($this->id(), $this->name(), $connection, 'The default database connection uses '.($posture->defaultDatabaseDriverName ?? 'an unknown driver').': the size is measured on MySQL, MariaDB, PostgreSQL and SQLite only.');
        }

        $size = $posture->databaseSize;

        if ($size === null) {
            return CheckResultData::skipped($this->id(), $this->name(), $connection, 'The size of the default database could not be measured (no file for an in-memory SQLite database, or the query failed).');
        }

        $warnAtMb = $configuration->databaseSizeWarnAtMb;
        $isTooLarge = $warnAtMb !== null && $size['sizeMb'] > $warnAtMb;
        $tables = array_map(static fn (array $table): string => $table['table'].' '.self::megabytes($table['sizeMb']), $size['largestTables']);

        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData(
                checkId: $this->id(),
                severity: $isTooLarge ? SeverityEnum::Medium : SeverityEnum::Info,
                status: $isTooLarge ? FindingStatusEnum::Warn : FindingStatusEnum::Pass,
                subject: $connection,
                message: 'The database of connection '.$connection.' takes '.self::megabytes($size['sizeMb']).($isTooLarge ? ' (more than '.$warnAtMb.' MB)' : '').($tables === [] ? '.' : '; largest tables: '.implode(', ', $tables).'.'),
                fixHint: $isTooLarge ? 'Prune old rows (logs, sessions, failed jobs, audit tables), archive them, or raise checks.laravel-database-size.warnAtMb.' : '',
                evidenceUrl: $isTooLarge ? 'https://laravel.com/docs/eloquent#pruning-models' : null,
                details: ['connection' => $connection, 'sizeMb' => $size['sizeMb'], 'largestTables' => $size['largestTables']],
            ),
        ]);
    }

    private static function megabytes(float $sizeMb): string
    {
        return rtrim(rtrim(number_format($sizeMb, 2, '.', ''), '0'), '.').' MB';
    }
}
