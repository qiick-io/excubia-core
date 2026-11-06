<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\LaravelDatabaseConnectionsCheck;
use Qiick\Excubia\Checks\LaravelDatabaseSizeCheck;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LaravelPostureData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * Runs a database statistics check on a hand-built posture.
 *
 * @param  array{sizeMb: float, largestTables: list<array{table: string, sizeMb: float}>}|null  $size
 */
function databaseStatisticsResult(HealthCheckContract $check, ?string $driver, ?int $connectionCount = null, ?array $size = null, ?ExcubiaConfigurationData $configuration = null, ?string $connection = 'mysql'): CheckResultData
{
    $snapshot = (new ProjectSnapshotData(null, null, [], [], null))->withLaravelPosture(new LaravelPostureData(
        environment: 'production',
        isDebugModeEnabled: false,
        isConfigurationCached: true,
        areRoutesCached: true,
        areEventsCached: true,
        isMaintenanceModeActive: false,
        pendingMigrations: [],
        failedJobCount: 0,
        oldestFailedJobAt: null,
        isSchedulerHeartbeatReadable: true,
        schedulerHeartbeatAt: null,
        collectedAt: new DateTimeImmutable,
        defaultDatabaseConnectionName: $connection,
        defaultDatabaseDriverName: $driver,
        databaseConnectionCount: $connectionCount,
        databaseSize: $size,
    ));

    return $check->run($snapshot, $configuration ?? ExcubiaConfigurationData::defaults());
}

/**
 * @param  array<string, mixed>  $checks
 */
function databaseStatisticsConfiguration(array $checks): ExcubiaConfigurationData
{
    return (new ExcubiaConfigurationLoader)->load(null, temporaryProject(['excubia.json' => json_encode(['checks' => $checks], JSON_THROW_ON_ERROR)]));
}

it('skips both checks without a posture', function (HealthCheckContract $check) {
    $result = $check->run(new ProjectSnapshotData(null, null, [], [], null), ExcubiaConfigurationData::defaults());

    expect($result->status->value)->toBe('skipped')
        ->and($result->findings[0]->message)->toContain('requires Artisan');
})->with([
    'connections' => fn () => new LaravelDatabaseConnectionsCheck,
    'size' => fn () => new LaravelDatabaseSizeCheck,
]);

it('skips both checks without a default connection', function (HealthCheckContract $check) {
    $result = databaseStatisticsResult($check, null, connection: null);

    expect($result->status->value)->toBe('skipped')
        ->and($result->findings[0]->message)->toBe('No default database connection is configured.');
})->with([
    'connections' => fn () => new LaravelDatabaseConnectionsCheck,
    'size' => fn () => new LaravelDatabaseSizeCheck,
]);

describe('laravel-database-connections', function () {
    it('passes up to warnAt, warns at medium above it and fails at high above failAt', function () {
        $check = new LaravelDatabaseConnectionsCheck;
        $warn = databaseStatisticsResult($check, 'pgsql', 51);
        $fail = databaseStatisticsResult($check, 'mysql', 101);

        expect(databaseStatisticsResult($check, 'mariadb', 50)->status->value)->toBe('pass')
            ->and(databaseStatisticsResult($check, 'mariadb', 50)->findings)->toBe([])
            ->and($warn->status->value)->toBe('warn')
            ->and($warn->findings[0]->severity->value)->toBe('medium')
            ->and($warn->findings[0]->subject)->toBe('mysql')
            ->and($warn->findings[0]->message)->toBe('The database of connection mysql has 51 open connections (more than 50).')
            ->and($warn->findings[0]->details)->toBe(['connection' => 'mysql', 'connectionCount' => 51])
            ->and($fail->status->value)->toBe('fail')
            ->and($fail->findings[0]->severity->value)->toBe('high')
            ->and($fail->findings[0]->message)->toBe('The database of connection mysql has 101 open connections (more than 100).');
    });

    it('reads checks.laravel-database-connections.warnAt and failAt', function () {
        $configuration = databaseStatisticsConfiguration(['laravel-database-connections' => ['warnAt' => 5, 'failAt' => 10]]);
        $check = new LaravelDatabaseConnectionsCheck;

        expect(databaseStatisticsResult($check, 'mysql', 5, configuration: $configuration)->status->value)->toBe('pass')
            ->and(databaseStatisticsResult($check, 'mysql', 6, configuration: $configuration)->status->value)->toBe('warn')
            ->and(databaseStatisticsResult($check, 'mysql', 11, configuration: $configuration)->status->value)->toBe('fail')
            ->and([ExcubiaConfigurationData::defaults()->databaseConnectionsWarnAt, ExcubiaConfigurationData::defaults()->databaseConnectionsFailAt])->toBe([50, 100])
            ->and(databaseStatisticsConfiguration(['laravel-database-connections' => ['failAt' => 200]])->databaseConnectionsWarnAt)->toBe(50)
            ->and(fn () => databaseStatisticsConfiguration(['laravel-database-connections' => ['warnAt' => 20, 'failAt' => 10]]))->toThrow(InvalidConfigurationException::class, 'laravel-database-connections needs 1 <= warnAt <= failAt.')
            ->and(fn () => databaseStatisticsConfiguration(['laravel-database-connections' => ['warnAt' => 0]]))->toThrow(InvalidConfigurationException::class, 'laravel-database-connections needs')
            ->and(fn () => databaseStatisticsConfiguration(['laravel-database-connections' => ['warnAt' => '50']]))->toThrow(InvalidConfigurationException::class, 'checks.laravel-database-connections.warnAt must be an integer.')
            ->and(fn () => databaseStatisticsConfiguration(['laravel-database-connections' => ['limit' => 5]]))->toThrow(InvalidConfigurationException::class);
    });

    it('skips other drivers and a count that could not be read', function () {
        $check = new LaravelDatabaseConnectionsCheck;
        $sqlite = databaseStatisticsResult($check, 'sqlite', connection: 'local');
        $unknown = databaseStatisticsResult($check, null);
        $unread = databaseStatisticsResult($check, 'pgsql');

        expect($sqlite->status->value)->toBe('skipped')
            ->and($sqlite->findings[0]->subject)->toBe('local')
            ->and($sqlite->findings[0]->message)->toBe('The default database connection uses sqlite: open connections are counted on MySQL, MariaDB and PostgreSQL only.')
            ->and($unknown->findings[0]->message)->toContain('uses an unknown driver')
            ->and($unread->status->value)->toBe('skipped')
            ->and($unread->findings[0]->message)->toBe('The open connections of the default database connection could not be counted.');
    });
});

describe('laravel-database-size', function () {
    $size = ['sizeMb' => 2048.5, 'largestTables' => [['table' => 'activity_log', 'sizeMb' => 1500.25], ['table' => 'users', 'sizeMb' => 2.0]]];

    it('reports the size and the largest tables as a pass row at info by default', function () use ($size) {
        $result = databaseStatisticsResult(new LaravelDatabaseSizeCheck, 'pgsql', size: $size, connection: 'pgsql');

        expect($result->status->value)->toBe('pass')
            ->and($result->findings)->toHaveCount(1)
            ->and($result->findings[0]->severity->value)->toBe('info')
            ->and($result->findings[0]->status->value)->toBe('pass')
            ->and($result->findings[0]->subject)->toBe('pgsql')
            ->and($result->findings[0]->message)->toBe('The database of connection pgsql takes 2048.5 MB; largest tables: activity_log 1500.25 MB, users 2 MB.')
            ->and($result->findings[0]->evidenceUrl)->toBeNull()
            ->and($result->findings[0]->details)->toBe(['connection' => 'pgsql', 'sizeMb' => 2048.5, 'largestTables' => $size['largestTables']])
            ->and(ExcubiaConfigurationData::defaults()->databaseSizeWarnAtMb)->toBeNull();
    });

    it('warns at medium above checks.laravel-database-size.warnAtMb', function () use ($size) {
        $check = new LaravelDatabaseSizeCheck;
        $warn = databaseStatisticsResult($check, 'mysql', size: $size, configuration: databaseStatisticsConfiguration(['laravel-database-size' => ['warnAtMb' => 2048]]));
        $under = databaseStatisticsResult($check, 'mysql', size: $size, configuration: databaseStatisticsConfiguration(['laravel-database-size' => ['warnAtMb' => 4096]]));

        expect($warn->status->value)->toBe('warn')
            ->and($warn->findings[0]->severity->value)->toBe('medium')
            ->and($warn->findings[0]->message)->toBe('The database of connection mysql takes 2048.5 MB (more than 2048 MB); largest tables: activity_log 1500.25 MB, users 2 MB.')
            ->and($warn->findings[0]->fixHint)->toContain('warnAtMb')
            ->and($under->status->value)->toBe('pass')
            ->and($under->findings[0]->severity->value)->toBe('info')
            ->and(databaseStatisticsConfiguration(['laravel-database-size' => []])->databaseSizeWarnAtMb)->toBeNull()
            ->and(fn () => databaseStatisticsConfiguration(['laravel-database-size' => ['warnAtMb' => 0]]))->toThrow(InvalidConfigurationException::class, 'checks.laravel-database-size.warnAtMb must be at least 1.')
            ->and(fn () => databaseStatisticsConfiguration(['laravel-database-size' => ['warnAtMb' => 1.5]]))->toThrow(InvalidConfigurationException::class, 'checks.laravel-database-size.warnAtMb must be an integer.')
            ->and(fn () => databaseStatisticsConfiguration(['laravel-database-size' => 100]))->toThrow(InvalidConfigurationException::class, 'checks.laravel-database-size must be an object.');
    });

    it('omits the table list from the message when there is none', function () {
        $result = databaseStatisticsResult(new LaravelDatabaseSizeCheck, 'sqlite', size: ['sizeMb' => 0.02, 'largestTables' => []], connection: 'sqlite');

        expect($result->findings[0]->message)->toBe('The database of connection sqlite takes 0.02 MB.');
    });

    it('skips other drivers and a size that could not be measured', function () {
        $check = new LaravelDatabaseSizeCheck;
        $sqlsrv = databaseStatisticsResult($check, 'sqlsrv');
        $unmeasured = databaseStatisticsResult($check, 'sqlite');

        expect($sqlsrv->status->value)->toBe('skipped')
            ->and($sqlsrv->findings[0]->message)->toBe('The default database connection uses sqlsrv: the size is measured on MySQL, MariaDB, PostgreSQL and SQLite only.')
            ->and($unmeasured->status->value)->toBe('skipped')
            ->and($unmeasured->findings[0]->message)->toContain('could not be measured');
    });
});
