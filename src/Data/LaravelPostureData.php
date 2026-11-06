<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

use DateTimeImmutable;

/**
 * Configuration posture of a booted Laravel app, read once by the Artisan
 * commands (`src/Laravel/LaravelPostureService.php`) and handed to the
 * `laravel-*` posture checks through the snapshot.
 *
 * Only booleans, counts, timestamps and names: never `.env` values, DSNs,
 * hostnames or paths. A null value means the fact could not be read (no
 * database, no failed job store, cache store down); the check then skips.
 * The standalone CLI cannot boot the app, so there the snapshot carries no
 * posture at all and every posture check is skipped.
 *
 * The production posture fields after `collectedAt` default to the safe
 * value, so a hand-built posture only names what it tests. The
 * infrastructure probe fields (#131) default to "not measured", so their
 * checks skip on a hand-built posture.
 */
final readonly class LaravelPostureData
{
    /**
     * Skip message of every posture check when the snapshot has no posture.
     */
    public const REQUIRES_ARTISAN_MESSAGE = 'This check requires Artisan: run php artisan excubia:check in the app.';

    /**
     * @param  list<string>|null  $pendingMigrations  migration names (no path) not run yet; null when they could not be read
     * @param  int|null  $failedJobCount  null when there is no failed job store or it could not be read
     * @param  DateTimeImmutable|null  $oldestFailedJobAt  when the oldest failed job failed; null when none or unknown
     * @param  DateTimeImmutable|null  $schedulerHeartbeatAt  last `excubia:heartbeat` run; null when never written
     * @param  array<string, string>  $driverNames  driver name per component (`queue`, `cache`, `session`, `mail`) of the default connection, store or mailer; a component is missing when it could not be read
     * @param  list<string>  $enabledDevelopmentPackageNames  development tool packages (Telescope, Debugbar, …) whose service provider is loaded and whose enable flag is on
     * @param  array<string, bool>  $databaseConnectionReachability  per probed connection name, true when it answered `select 1`
     * @param  string|null  $cacheStoreName  default cache store; null when none is configured
     * @param  bool|null  $isCacheStoreWorking  the write, read and delete probe worked; null when not probed (no store, `null` driver)
     * @param  list<string>  $unwritableDirectoryNames  `storage` and / or `bootstrap/cache` when not a writable directory
     * @param  int|null  $diskUsedPercent  used share of the disk holding `storage/`; null when it could not be measured
     * @param  list<array{queue: string, pendingCount: int, oldestAgeMinutes: int|null}>|null  $queueBacklogs  pending jobs per queue of the default queue connection; null when the driver is not database or redis, or the store could not be read
     * @param  list<array{taskId: string, task: string, expression: string, timezone: string, withoutOverlapping: bool, onOneServer: bool, runsInMaintenanceMode: bool, environments: list<string>}>|null  $scheduledTasks  the schedule inventory (#146) for the report `schedule`; null when not read
     */
    public function __construct(
        public string $environment,
        public bool $isDebugModeEnabled,
        public bool $isConfigurationCached,
        public bool $areRoutesCached,
        public bool $areEventsCached,
        public bool $isMaintenanceModeActive,
        public ?array $pendingMigrations,
        public ?int $failedJobCount,
        public ?DateTimeImmutable $oldestFailedJobAt,
        public bool $isSchedulerHeartbeatReadable,
        public ?DateTimeImmutable $schedulerHeartbeatAt,
        public DateTimeImmutable $collectedAt,
        public bool $hasApplicationKey = true,
        public bool $isApplicationUrlHttps = false,
        public bool $isSessionCookieSecure = true,
        public bool $isSessionCookieHttpOnly = true,
        public bool $hasCorsWildcardOrigin = false,
        public bool $isCorsCredentialSupportEnabled = false,
        public array $driverNames = [],
        public bool $isDefaultLogLevelDebug = false,
        public array $enabledDevelopmentPackageNames = [],
        public array $databaseConnectionReachability = [],
        public ?string $cacheStoreName = null,
        public ?bool $isCacheStoreWorking = null,
        public array $unwritableDirectoryNames = [],
        public ?int $diskUsedPercent = null,
        public ?array $queueBacklogs = null,
        public ?array $scheduledTasks = null,
    ) {}
}
