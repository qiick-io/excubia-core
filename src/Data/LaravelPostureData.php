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
     * Skip message of `php-opcache` and `php-production-ini` when there is no
     * web-SAPI snapshot: the CLI that runs Artisan is never checked instead.
     */
    public const PHP_WEB_RUNTIME_UNKNOWN_MESSAGE = 'PHP web settings unknown: enable the health endpoint so Excubia can read the web SAPI (the CLI uses different ini settings).';

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
     * @param  list<array{connection: string, queue: string, lastSeenAt: DateTimeImmutable|null}>|null  $queueWorkerHeartbeats  last worker heartbeat per queue of `$queueBacklogs` (#151); null when the backlog or the cache store could not be read
     * @param  bool  $isHorizonInstalled  Laravel Horizon's master supervisor repository is bound in the container
     * @param  array{status: string, processes: int, supervisors: list<string>}|null  $horizonStatus  Horizon's `inactive`, `paused` or `running` status, its worker process count and its supervisor names (without the host prefix); null when Horizon is missing or could not be read
     * @param  list<array{taskId: string, task: string, expression: string, timezone: string, withoutOverlapping: bool, onOneServer: bool, runsInMaintenanceMode: bool, environments: list<string>, lateAfterMinutes: int}>|null  $scheduledTasks  the schedule inventory (#146) for the report `schedule`; null when not read
     * @param  bool  $isBackupPackageInstalled  spatie/laravel-backup's `BackupDestination` class exists (#171)
     * @param  list<array{disk: string, isReachable: bool, count: int, newestAt: DateTimeImmutable|null, newestSizeBytes: int|null, previousSizeBytes: int|null}>|null  $backupDestinations  each monitored backup destination by disk name (never a path or bucket); null when spatie/laravel-backup is missing or its configuration could not be read
     * @param  string|null  $defaultDatabaseConnectionName  name of the default database connection (#169); null when none is configured
     * @param  string|null  $defaultDatabaseDriverName  driver of that connection (`mysql`, `pgsql`, `sqlite`, …); null when unknown
     * @param  int|null  $databaseConnectionCount  open connections on the default connection's server (MySQL, MariaDB) or database (PostgreSQL); null when not measured
     * @param  array{sizeMb: float, largestTables: list<array{table: string, sizeMb: float}>}|null  $databaseSize  size of the default database and its five largest tables, in MB; null when not measured
     * @param  list<array{connection: string, isCacheConnection: bool, usedBytes: int|null, maxBytes: int|null, policy: string|null}>|null  $redisMemory  `INFO memory` of each Redis connection used by the default cache store, queue connection or session, or of `checks.laravel-redis-memory.connections` (#170): used and max memory in bytes (`maxBytes` 0 when unbounded) and the eviction policy; `usedBytes` null when the connection could not be read; an empty list when no Redis connection is used; null when not measured
     * @param  array{sapi: string, ageMinutes: int, isOpcacheEnabled: bool, hitRate: float|null, memoryUsedPercent: float|null, cachedScripts: int|null, isValidateTimestampsEnabled: bool, isDisplayErrorsEnabled: bool, isExposePhpEnabled: bool, isLogErrorsEnabled: bool}|null  $phpRuntime  OPcache status and production php.ini directives (#172): the web-SAPI snapshot the health endpoint stored (`sapi` `web`, `ageMinutes` its age); null when there is none, because the CLI values of this run are never used
     * @param  array{server: string, maxRequests: int|null}|null  $octane  Laravel Octane's server (`swoole`, `roadrunner`, `frankenphp`, else `unknown`) and `octane.max_requests` (null when unset or not an integer) (#213); null when laravel/octane is not installed
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
        public ?array $queueWorkerHeartbeats = null,
        public bool $isHorizonInstalled = false,
        public ?array $horizonStatus = null,
        public ?array $scheduledTasks = null,
        public bool $isBackupPackageInstalled = false,
        public ?array $backupDestinations = null,
        public ?string $defaultDatabaseConnectionName = null,
        public ?string $defaultDatabaseDriverName = null,
        public ?int $databaseConnectionCount = null,
        public ?array $databaseSize = null,
        public ?array $redisMemory = null,
        public ?array $phpRuntime = null,
        public ?array $octane = null,
    ) {}

    /**
     * Where the `php-opcache` and `php-production-ini` values come from, for
     * their messages: the web-SAPI snapshot of the health endpoint.
     */
    public function phpRuntimeSource(): string
    {
        return 'Checked on the web SAPI (health endpoint snapshot, '.($this->phpRuntime['ageMinutes'] ?? 0).' minutes old).';
    }
}
