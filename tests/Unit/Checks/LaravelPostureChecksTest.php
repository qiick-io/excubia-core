<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\LaravelFailedJobsCheck;
use Qiick\Excubia\Checks\LaravelPendingMigrationsCheck;
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
