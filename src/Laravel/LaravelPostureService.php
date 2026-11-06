<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use PDO;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Contracts\DiskSpaceContract;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LaravelPostureData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Services\DiskSpaceService;
use Throwable;

/**
 * Reads the configuration posture of the booted app for the `laravel-*`
 * posture checks. Only the Artisan commands call it: the standalone CLI
 * cannot boot the app and must not load `illuminate/*`.
 *
 * Read-only: it asks the container, the migrator, the failed job store and
 * the cache, and writes nothing except the cache probe key, which it deletes
 * right away. A source that throws (no database, cache store down) becomes
 * null or false in the data object so the check skips or reports it; the
 * exception message is dropped because it can carry a DSN or a hostname.
 *
 * The infrastructure probes (#131) run `select 1` on each database
 * connection, write / read / delete a random cache key, test that
 * `storage/` and `bootstrap/cache` are writable, measure the disk and count
 * pending jobs. Connections opened only for the probe get a short connect
 * timeout and are closed again.
 *
 * ponytail: Redis, Memcached and `select 1` on an already open connection
 * use the client's own timeouts; wrap them in a deadline if a hung service
 * ever blocks a run.
 */
final class LaravelPostureService
{
    /**
     * Connect timeout, in seconds, for a database connection opened by the probe.
     */
    private const PROBE_TIMEOUT_SECONDS = 3;

    /**
     * Drivers whose PDO connector honours `PDO::ATTR_TIMEOUT` as a connect timeout.
     */
    private const TIMEOUT_AWARE_DATABASE_DRIVERS = ['mysql', 'mariadb', 'pgsql'];

    /**
     * Development tools that must not run in production: their service
     * providers (old and new namespaces) and the config flag that turns them
     * off. `followsDebugWhenUnset` is true for the tools that default to
     * `app.debug` when the flag is null; the others default to on.
     *
     * @var array<string, array{providers: list<string>, flag: string|null, followsDebugWhenUnset: bool}>
     */
    private const DEVELOPMENT_PACKAGES = [
        'laravel/telescope' => ['providers' => ['Laravel\\Telescope\\TelescopeServiceProvider'], 'flag' => 'telescope.enabled', 'followsDebugWhenUnset' => false],
        'barryvdh/laravel-debugbar' => ['providers' => ['Barryvdh\\Debugbar\\ServiceProvider', 'Fruitcake\\LaravelDebugbar\\ServiceProvider'], 'flag' => 'debugbar.enabled', 'followsDebugWhenUnset' => true],
        'spatie/laravel-ray' => ['providers' => ['Spatie\\LaravelRay\\RayServiceProvider'], 'flag' => 'ray.enable', 'followsDebugWhenUnset' => false],
        'laravel/pail' => ['providers' => ['Laravel\\Pail\\PailServiceProvider'], 'flag' => null, 'followsDebugWhenUnset' => false],
        'itsgoingd/clockwork' => ['providers' => ['Clockwork\\Support\\Laravel\\ClockworkServiceProvider'], 'flag' => 'clockwork.enable', 'followsDebugWhenUnset' => true],
    ];

    public function __construct(private readonly DiskSpaceContract $diskSpace = new DiskSpaceService) {}

    /**
     * The `excubia.json` the run will use, for the probe lists. An invalid
     * file gives the defaults: the command reports the error itself.
     */
    public static function configuration(Application $application, ?string $configPath): ExcubiaConfigurationData
    {
        try {
            return (new ExcubiaConfigurationLoader)->load($configPath, $application->basePath());
        } catch (InvalidConfigurationException) {
            return ExcubiaConfigurationData::defaults();
        }
    }

    /**
     * `$configuration` supplies the extra database connections and Redis
     * queues to probe; null means the defaults (default connection and queue).
     */
    public function collect(Application $application, ?DateTimeImmutable $now = null, ?ExcubiaConfigurationData $configuration = null): LaravelPostureData
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $configuration ??= ExcubiaConfigurationData::defaults();
        $heartbeat = $this->schedulerHeartbeat($application);
        $failedJobs = $this->failedJobs($application);
        $config = $application->make('config');
        $applicationKey = $config->get('app.key');
        $cacheProbe = $this->cacheProbe($application, $config);

        return new LaravelPostureData(
            environment: $application->environment(),
            isDebugModeEnabled: (bool) $config->get('app.debug'),
            isConfigurationCached: $application->configurationIsCached(),
            areRoutesCached: $application->routesAreCached(),
            areEventsCached: $application->eventsAreCached(),
            isMaintenanceModeActive: $application->isDownForMaintenance(),
            pendingMigrations: $this->pendingMigrations($application),
            failedJobCount: $failedJobs['count'],
            oldestFailedJobAt: $failedJobs['oldestFailedAt'],
            isSchedulerHeartbeatReadable: $heartbeat['isReadable'],
            schedulerHeartbeatAt: $heartbeat['at'],
            collectedAt: $now,
            hasApplicationKey: is_string($applicationKey) && $applicationKey !== '',
            isApplicationUrlHttps: str_starts_with(strtolower((string) $config->get('app.url')), 'https://'),
            isSessionCookieSecure: (bool) $config->get('session.secure'),
            isSessionCookieHttpOnly: (bool) $config->get('session.http_only', true),
            hasCorsWildcardOrigin: in_array('*', (array) $config->get('cors.allowed_origins', []), true),
            isCorsCredentialSupportEnabled: (bool) $config->get('cors.supports_credentials'),
            driverNames: $this->driverNames($config),
            isDefaultLogLevelDebug: $this->isDefaultLogLevelDebug($config),
            enabledDevelopmentPackageNames: $this->enabledDevelopmentPackageNames($application, $config),
            databaseConnectionReachability: $this->databaseConnectionReachability($application, $config, $configuration->databaseConnectionNames),
            cacheStoreName: $cacheProbe['store'],
            isCacheStoreWorking: $cacheProbe['isWorking'],
            unwritableDirectoryNames: $this->unwritableDirectoryNames($application),
            diskUsedPercent: $this->diskSpace->usedPercent($application->storagePath()),
            queueBacklogs: $this->queueBacklogs($application, $config, $configuration->queueBacklogQueueNames, $now),
            scheduledTasks: $this->scheduledTasks($application),
        );
    }

    /**
     * Whether each connection answers `select 1`: the default one, then the
     * configured extra names. An unknown name counts as unreachable.
     *
     * @param  list<string>  $extraNames
     * @return array<string, bool>
     */
    private function databaseConnectionReachability(Application $application, Repository $config, array $extraNames): array
    {
        $default = $config->get('database.default');
        $names = array_values(array_unique(array_filter(
            [is_string($default) ? $default : '', ...$extraNames],
            static fn (string $name): bool => $name !== '',
        )));
        $reachability = [];

        foreach ($names as $name) {
            $reachability[$name] = $this->isDatabaseReachable($application, $config, $name);
        }

        return $reachability;
    }

    /**
     * Runs `select 1`. A connection the app has not opened yet is opened with
     * a short connect timeout (MySQL, MariaDB, PostgreSQL), then closed and
     * its configuration restored, so the probe leaves no trace.
     */
    private function isDatabaseReachable(Application $application, Repository $config, string $name): bool
    {
        $key = 'database.connections.'.$name;
        $settings = $config->get($key);

        if (! is_array($settings)) {
            return false;
        }

        $shouldLimitTimeout = false;

        try {
            /** @var DatabaseManager $database */
            $database = $application->make('db');
            $options = is_array($settings['options'] ?? null) ? $settings['options'] : [];
            $shouldLimitTimeout = ! array_key_exists($name, $database->getConnections())
                && in_array($settings['driver'] ?? null, self::TIMEOUT_AWARE_DATABASE_DRIVERS, true)
                && ! array_key_exists(PDO::ATTR_TIMEOUT, $options);

            if ($shouldLimitTimeout) {
                $config->set($key.'.options', $options + [PDO::ATTR_TIMEOUT => self::PROBE_TIMEOUT_SECONDS]);
            }

            $database->connection($name)->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            if ($shouldLimitTimeout) {
                $application->make('db')->purge($name);
                $config->set($key, $settings);
            }
        }
    }

    /**
     * Writes a random key to the default cache store, reads it back and
     * deletes it. Not probed (null) with no default store or the `null`
     * driver, which stores nothing by design.
     *
     * @return array{store: string|null, isWorking: bool|null}
     */
    private function cacheProbe(Application $application, Repository $config): array
    {
        $storeName = $config->get('cache.default');

        if (! is_string($storeName) || $storeName === '') {
            return ['store' => null, 'isWorking' => null];
        }

        if ($config->get('cache.stores.'.$storeName.'.driver') === 'null') {
            return ['store' => $storeName, 'isWorking' => null];
        }

        $key = 'excubia:cache-probe:'.bin2hex(random_bytes(8));
        $value = bin2hex(random_bytes(8));

        try {
            $store = $application->make('cache')->store($storeName);
            $isWritten = $store->put($key, $value, 60);
            $isRead = $store->get($key) === $value;
            $isDeleted = $store->forget($key);

            return ['store' => $storeName, 'isWorking' => $isWritten && $isRead && $isDeleted];
        } catch (Throwable) {
            return ['store' => $storeName, 'isWorking' => false];
        }
    }

    /**
     * Relative names of the framework directories that are not writable.
     *
     * @return list<string>
     */
    private function unwritableDirectoryNames(Application $application): array
    {
        $directories = [
            'storage' => $application->storagePath(),
            'bootstrap/cache' => $application->bootstrapPath('cache'),
        ];
        $names = [];

        foreach ($directories as $name => $directory) {
            if (! is_dir($directory) || ! is_writable($directory)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Pending jobs and the age of the oldest per queue, for the default queue
     * connection. The database driver reads every queue in its table with one
     * grouped query (reserved and delayed jobs are not pending); the Redis
     * driver reads the default queue and the configured extra queues, using
     * the `createdAt` of the job at the head of the list. Payloads are never
     * kept. Null for other drivers or when the store cannot be read.
     *
     * @param  list<string>  $extraQueueNames
     * @return list<array{queue: string, pendingCount: int, oldestAgeMinutes: int|null}>|null
     */
    private function queueBacklogs(Application $application, Repository $config, array $extraQueueNames, DateTimeImmutable $now): ?array
    {
        $settings = $config->get('queue.connections.'.$config->get('queue.default'));

        if (! is_array($settings)) {
            return null;
        }

        try {
            return match ($settings['driver'] ?? null) {
                'database' => $this->databaseQueueBacklogs($application, $settings, $now),
                'redis' => $this->redisQueueBacklogs($application, $settings, $extraQueueNames, $now),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<mixed>  $settings
     * @return list<array{queue: string, pendingCount: int, oldestAgeMinutes: int|null}>
     */
    private function databaseQueueBacklogs(Application $application, array $settings, DateTimeImmutable $now): array
    {
        $connection = $settings['connection'] ?? null;
        $rows = $application->make('db')
            ->connection(is_string($connection) ? $connection : null)
            ->table(is_string($settings['table'] ?? null) ? $settings['table'] : 'jobs')
            ->selectRaw('queue, count(*) as pending_count, min(available_at) as oldest_available_at')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now->getTimestamp())
            ->groupBy('queue')
            ->orderBy('queue')
            ->get();
        $backlogs = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $backlogs[] = [
                'queue' => (string) $row['queue'],
                'pendingCount' => (int) $row['pending_count'],
                'oldestAgeMinutes' => $this->ageMinutes(is_numeric($row['oldest_available_at']) ? (int) $row['oldest_available_at'] : null, $now),
            ];
        }

        return $backlogs;
    }

    /**
     * @param  array<mixed>  $settings
     * @param  list<string>  $extraQueueNames
     * @return list<array{queue: string, pendingCount: int, oldestAgeMinutes: int|null}>
     */
    private function redisQueueBacklogs(Application $application, array $settings, array $extraQueueNames, DateTimeImmutable $now): array
    {
        $connection = $settings['connection'] ?? null;
        $redis = $application->make('redis')->connection(is_string($connection) ? $connection : 'default');
        $defaultQueue = is_string($settings['queue'] ?? null) ? $settings['queue'] : 'default';
        $backlogs = [];

        foreach (array_values(array_unique([$defaultQueue, ...$extraQueueNames])) as $queue) {
            $key = 'queues:'.$queue;
            $pendingCount = (int) $redis->llen($key);
            $createdAt = null;

            if ($pendingCount > 0) {
                $payload = json_decode((string) $redis->lindex($key, 0), true);
                $createdAt = is_array($payload) && is_int($payload['createdAt'] ?? null) ? $payload['createdAt'] : null;
            }

            $backlogs[] = ['queue' => $queue, 'pendingCount' => $pendingCount, 'oldestAgeMinutes' => $this->ageMinutes($createdAt, $now)];
        }

        return $backlogs;
    }

    private function ageMinutes(?int $timestamp, DateTimeImmutable $now): ?int
    {
        return $timestamp === null ? null : intdiv(max(0, $now->getTimestamp() - $timestamp), 60);
    }

    /**
     * Driver of the default queue connection, cache store and mailer, and the
     * session driver. Only the driver name is kept, never the connection
     * settings.
     *
     * @return array<string, string>
     */
    private function driverNames(Repository $config): array
    {
        $candidates = [
            'queue' => $config->get('queue.connections.'.$config->get('queue.default').'.driver'),
            'cache' => $config->get('cache.stores.'.$config->get('cache.default').'.driver'),
            'session' => $config->get('session.driver'),
            'mail' => $config->get('mail.mailers.'.$config->get('mail.default').'.transport'),
        ];

        return array_filter($candidates, fn (mixed $driver): bool => is_string($driver) && $driver !== '');
    }

    /**
     * True when the default log channel, or one of the channels it stacks,
     * logs at `debug`. A channel without a level logs at `debug` (Monolog's
     * default).
     */
    private function isDefaultLogLevelDebug(Repository $config): bool
    {
        $default = (string) $config->get('logging.default');
        $channel = (array) $config->get('logging.channels.'.$default, []);
        $channelNames = ($channel['driver'] ?? null) === 'stack' ? (array) ($channel['channels'] ?? []) : [$default];

        foreach ($channelNames as $channelName) {
            $level = $config->get('logging.channels.'.$channelName.'.level', 'debug');

            if (is_string($level) && strtolower($level) === 'debug') {
                return true;
            }
        }

        return false;
    }

    /**
     * Names of the development tool packages whose provider is registered
     * and whose enable flag is on.
     *
     * @return list<string>
     */
    private function enabledDevelopmentPackageNames(Application $application, Repository $config): array
    {
        $names = [];

        foreach (self::DEVELOPMENT_PACKAGES as $name => $package) {
            $isRegistered = false;

            foreach ($package['providers'] as $provider) {
                $isRegistered = $isRegistered || $application->getProviders($provider) !== [];
            }

            if (! $isRegistered) {
                continue;
            }

            $flag = $package['flag'] === null ? true : $config->get($package['flag']);

            if ($flag === null) {
                $flag = $package['followsDebugWhenUnset'] ? $config->get('app.debug') : true;
            }

            if ((bool) $flag) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Migration names (no path) found in the migrator paths and
     * `database/migrations` that the default connection has not run. When
     * the migrations table does not exist yet, every migration is pending.
     *
     * @return list<string>|null
     */
    private function pendingMigrations(Application $application): ?array
    {
        try {
            /** @var Migrator $migrator */
            $migrator = $application->make('migrator');
            $paths = array_merge($migrator->paths(), [$application->databasePath('migrations')]);
            $names = array_keys($migrator->getMigrationFiles($paths));
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

            return array_values(array_diff($names, $ran));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Count and oldest `failed_at` of the failed job store. The database
     * drivers are queried with COUNT and MIN so no payload is loaded.
     *
     * ponytail: the file and DynamoDB stores are read with all(); fine for
     * the usual handful of failed jobs, add a dedicated query if a project
     * keeps thousands there.
     *
     * @return array{count: int|null, oldestFailedAt: DateTimeImmutable|null}
     */
    private function failedJobs(Application $application): array
    {
        $unknown = ['count' => null, 'oldestFailedAt' => null];

        try {
            $config = $application->make('config');
            $timezone = new DateTimeZone((string) ($config->get('app.timezone') ?: 'UTC'));
            /** @var FailedJobProviderInterface $failer */
            $failer = $application->make('queue.failer');

            if ($failer instanceof NullFailedJobProvider) {
                return $unknown;
            }

            if (in_array($config->get('queue.failed.driver'), ['database', 'database-uuids'], true)) {
                $query = $application->make('db')
                    ->connection($config->get('queue.failed.database'))
                    ->table((string) ($config->get('queue.failed.table') ?: 'failed_jobs'));

                return [
                    'count' => (int) $query->count(),
                    'oldestFailedAt' => $this->failedAt($query->min('failed_at'), $timezone),
                ];
            }

            $oldest = null;
            $failedJobs = $failer->all();

            foreach ($failedJobs as $failedJob) {
                $failedAt = $this->failedAt(((array) $failedJob)['failed_at'] ?? null, $timezone);

                if ($failedAt !== null && ($oldest === null || $failedAt < $oldest)) {
                    $oldest = $failedAt;
                }
            }

            return ['count' => count($failedJobs), 'oldestFailedAt' => $oldest];
        } catch (Throwable) {
            return $unknown;
        }
    }

    private function failedAt(mixed $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if (is_int($value)) {
            return (new DateTimeImmutable('@'.$value))->setTimezone($timezone);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The schedule inventory (#146): every event of the app's schedule, in
     * schedule order, named as the runtime `scheduledTask` aggregates name
     * it, with its `taskId`. Only names, the cron expression, the timezone
     * name, the flags and the environment names: never arguments, the
     * command line or the output path. A task without a timezone runs in
     * the PHP default one, which Laravel sets from `app.timezone`. Null when
     * the schedule could not be read.
     *
     * @return list<array{taskId: string, task: string, expression: string, timezone: string, withoutOverlapping: bool, onOneServer: bool, runsInMaintenanceMode: bool, environments: list<string>}>|null
     */
    private function scheduledTasks(Application $application): ?array
    {
        try {
            $events = $application->make(Schedule::class)->events();
        } catch (Throwable) {
            return null;
        }

        $tasks = [];

        foreach ($events as $event) {
            $task = ScheduledTaskIdentityService::name($event, $application->basePath());
            $expression = (string) $event->expression;
            $timezone = $event->timezone;

            $tasks[] = [
                'taskId' => ScheduledTaskIdentityService::id($task, $expression),
                'task' => $task,
                'expression' => $expression,
                'timezone' => $timezone instanceof DateTimeZone ? $timezone->getName() : (is_string($timezone) && $timezone !== '' ? $timezone : date_default_timezone_get()),
                'withoutOverlapping' => (bool) $event->withoutOverlapping,
                'onOneServer' => (bool) $event->onOneServer,
                'runsInMaintenanceMode' => (bool) $event->evenInMaintenanceMode,
                'environments' => array_values(array_filter(array_map(strval(...), (array) $event->environments), static fn (string $name): bool => $name !== '')),
            ];
        }

        return $tasks;
    }

    /**
     * Last run of `excubia:heartbeat`, from the default cache store.
     *
     * @return array{isReadable: bool, at: DateTimeImmutable|null}
     */
    private function schedulerHeartbeat(Application $application): array
    {
        try {
            $timestamp = $application->make('cache')->get(ExcubiaHeartbeatCommand::CACHE_KEY);
        } catch (Throwable) {
            return ['isReadable' => false, 'at' => null];
        }

        if (! is_int($timestamp)) {
            return ['isReadable' => true, 'at' => null];
        }

        return ['isReadable' => true, 'at' => new DateTimeImmutable('@'.$timestamp)];
    }
}
