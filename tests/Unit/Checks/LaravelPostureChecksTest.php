<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\LaravelDebugModeCheck;
use Qiick\Excubia\Checks\LaravelFailedJobsCheck;
use Qiick\Excubia\Checks\LaravelMaintenanceModeCheck;
use Qiick\Excubia\Checks\LaravelPendingMigrationsCheck;
use Qiick\Excubia\Checks\LaravelProductionCachesCheck;
use Qiick\Excubia\Checks\LaravelSchedulerHeartbeatCheck;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LaravelPostureData;
use Qiick\Excubia\Data\ProjectSnapshotData;

/**
 * A posture built by hand: the Laravel tests read real apps, these cover
 * the message branches that are awkward to reach there.
 *
 * @param  list<string>|null  $pendingMigrations
 */
function handBuiltPosture(?array $pendingMigrations = [], ?int $failedJobCount = 0): ProjectSnapshotData
{
    return (new ProjectSnapshotData(null, null, [], [], null))->withLaravelPosture(new LaravelPostureData(
        environment: 'production',
        isDebugModeEnabled: false,
        isConfigurationCached: true,
        areRoutesCached: true,
        areEventsCached: true,
        isMaintenanceModeActive: false,
        pendingMigrations: $pendingMigrations,
        failedJobCount: $failedJobCount,
        oldestFailedJobAt: null,
        isSchedulerHeartbeatReadable: true,
        schedulerHeartbeatAt: null,
        collectedAt: new DateTimeImmutable('2026-11-03 12:00:00', new DateTimeZone('UTC')),
    ));
}

it('names three pending migrations and counts the rest', function () {
    $result = (new LaravelPendingMigrationsCheck)->run(
        handBuiltPosture(pendingMigrations: ['one', 'two', 'three', 'four', 'five']),
        ExcubiaConfigurationData::defaults(),
    );

    expect($result->findings[0]->message)->toBe('5 pending migrations: one, two, three and 2 more.');
});

it('reports failed jobs at medium when their age is unknown', function () {
    $finding = (new LaravelFailedJobsCheck)->run(handBuiltPosture(failedJobCount: 3), ExcubiaConfigurationData::defaults())->findings[0];

    expect($finding->message)->toBe('3 failed jobs.')
        ->and($finding->severity->value)->toBe('medium');
});

it('puts only counts and ages in the posture details', function () {
    $collectedAt = new DateTimeImmutable('2026-11-03 12:00:00', new DateTimeZone('UTC'));
    $posture = (new ProjectSnapshotData(null, null, [], [], null))->withLaravelPosture(new LaravelPostureData(
        environment: 'production',
        isDebugModeEnabled: true,
        isConfigurationCached: false,
        areRoutesCached: true,
        areEventsCached: true,
        isMaintenanceModeActive: true,
        pendingMigrations: ['2026_01_01_000000_create_orders_table', '2026_01_02_000000_add_status'],
        failedJobCount: 4,
        oldestFailedJobAt: $collectedAt->modify('-30 hours'),
        isSchedulerHeartbeatReadable: true,
        schedulerHeartbeatAt: $collectedAt->modify('-45 minutes'),
        collectedAt: $collectedAt,
    ));
    $configuration = ExcubiaConfigurationData::defaults();
    $neverRan = (new LaravelSchedulerHeartbeatCheck)->run(handBuiltPosture(), $configuration)->findings[0];

    expect((new LaravelPendingMigrationsCheck)->run($posture, $configuration)->findings[0]->details)->toBe(['count' => 2])
        ->and((new LaravelFailedJobsCheck)->run($posture, $configuration)->findings[0]->details)->toBe(['count' => 4, 'oldestAgeHours' => 30])
        ->and((new LaravelFailedJobsCheck)->run(handBuiltPosture(failedJobCount: 1), $configuration)->findings[0]->details)->toBe(['count' => 1, 'oldestAgeHours' => null])
        ->and((new LaravelSchedulerHeartbeatCheck)->run($posture, $configuration)->findings[0]->details)->toBe(['minutesSinceLastRun' => 45])
        ->and($neverRan->details)->toBe(['minutesSinceLastRun' => null])
        ->and((new LaravelDebugModeCheck)->run($posture, $configuration)->findings[0]->details)->toBe([])
        ->and((new LaravelProductionCachesCheck)->run($posture, $configuration)->findings[0]->details)->toBe([])
        ->and((new LaravelMaintenanceModeCheck)->run($posture, $configuration)->findings[0]->details)->toBe([]);
});
