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
 * Fails at critical for each database connection that did not answer
 * `select 1`: the default connection and those listed in
 * `checks.laravel-database.connections`. Only the connection name is
 * reported, never the host, DSN or the driver's error.
 */
final class LaravelDatabaseCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'laravel-database';
    }

    public function name(): string
    {
        return 'Database';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'database', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->databaseConnectionReachability === []) {
            return CheckResultData::skipped($this->id(), $this->name(), 'database', 'No database connection is configured.');
        }

        $findings = [];

        foreach ($posture->databaseConnectionReachability as $connection => $isReachable) {
            if ($isReachable) {
                continue;
            }

            $connection = (string) $connection;
            $findings[] = new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::Critical,
                status: FindingStatusEnum::Fail,
                subject: $connection,
                message: 'The database connection '.$connection.' did not answer a trivial query (unreachable, refused or not configured).',
                fixHint: 'Check that the database server is up and that config/database.php and the DB_* variables of this connection are right.',
                evidenceUrl: 'https://laravel.com/docs/database#configuration',
                details: ['connection' => $connection],
            );
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }
}
