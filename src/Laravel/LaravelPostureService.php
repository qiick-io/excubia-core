<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
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
use Qiick\Excubia\Services\DatabaseStatisticsService;
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
 * For `laravel-database-connections` and `laravel-database-size` (#169)
 * it runs read-only statistics queries on the default connection through
 * `DatabaseStatisticsService`, opened like the `select 1` probe.
 *
 * For `laravel-queue-workers` (#151) it reads the worker heartbeat key of
 * each queue the backlog probe read and, when Laravel Horizon is installed,
 * Horizon's master supervisor and supervisor repositories.
 *
 * For `laravel-backups` (#171), when spatie/laravel-backup is installed, it
 * lists the backups of each monitored destination through the package's
 * `BackupDestination` API: count, newest date and the two newest sizes.
 *
 * For `laravel-redis-memory` (#170) it reads `INFO memory` on each Redis
 * connection the default cache store, the default queue connection or the
 * session use (or on `checks.laravel-redis-memory.connections`): used and
 * max memory and the eviction policy, nothing else of the reply.
 *
 * For `laravel-octane-config` (#213), when Laravel Octane is installed, it
 * reads `octane.server` and `octane.max_requests`, nothing else.
 *
 * For `laravel-reverb` (#215), when Laravel Reverb is installed and is the
 * default broadcast driver, it opens one TCP connection to the host and port
 * the app broadcasts to, with a 2 s timeout, sends nothing and closes it.
 * Only whether it connected and how long it took are kept.
 *
 * For `laravel-cashier` (#217), when Laravel Cashier (Stripe) or Cashier
 * Paddle is installed, it reads whether the webhook secret is set, whether
 * the keys start with a test prefix (or Paddle's sandbox flag), and whether
 * a route reaches Cashier's webhook controller: booleans only.
 *
 * For `php-opcache` and `php-production-ini` (#172) it reads the web-SAPI
 * snapshot the health endpoint stores (`PhpWebRuntimeSnapshotService`).
 * Without one the PHP runtime stays unknown: the CLI that runs Artisan has
 * its own ini settings, so its values would be misleading.
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

    /**
     * Horizon contracts, read only when Horizon is installed (it is not a
     * dependency of this package).
     */
    private const HORIZON_MASTER_SUPERVISOR_REPOSITORY = 'Laravel\\Horizon\\Contracts\\MasterSupervisorRepository';

    private const HORIZON_SUPERVISOR_REPOSITORY = 'Laravel\\Horizon\\Contracts\\SupervisorRepository';

    /**
     * spatie/laravel-backup's destination class, used only when installed (it
     * is not a dependency of this package).
     */
    private const BACKUP_DESTINATION = 'Spatie\\Backup\\BackupDestination\\BackupDestination';

    /**
     * Laravel Octane's first event class, used only to tell whether Octane
     * is installed (it is not a dependency of this package).
     */
    private const OCTANE_WORKER_STARTING = 'Laravel\\Octane\\Events\\WorkerStarting';

    /**
     * Laravel Reverb's message event, used only to tell whether Reverb is
     * installed (it is not a dependency of this package).
     */
    private const REVERB_MESSAGE_SENT = 'Laravel\\Reverb\\Events\\MessageSent';

    private const REVERB_CONNECT_TIMEOUT_SECONDS = 2.0;

    /**
     * Laravel Cashier (Stripe, `laravel/cashier`) and Cashier Paddle
     * (`laravel/cashier-paddle`) (#217): the class that tells each one is
     * installed (neither is a dependency) and its webhook controller.
     */
    private const CASHIER_PACKAGES = [
        'stripe' => ['Laravel\\Cashier\\Cashier', 'Laravel\\Cashier\\Http\\Controllers\\WebhookController'],
        'paddle' => ['Laravel\\Paddle\\Cashier', 'Laravel\\Paddle\\Http\\Controllers\\WebhookController'],
    ];

    /**
     * Prefixes of Stripe's test keys (secret, restricted, publishable) and
     * of Paddle Billing's sandbox API key and test client-side token.
     */
    private const STRIPE_TEST_KEY_PREFIXES = ['sk_test_', 'rk_test_', 'pk_test_'];

    private const PADDLE_TEST_KEY_PREFIXES = ['pdl_sdbx_', 'test_'];

    public function __construct(
        private readonly DiskSpaceContract $diskSpace = new DiskSpaceService,
        private readonly DatabaseStatisticsService $databaseStatistics = new DatabaseStatisticsService,
    ) {}

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
     *
     * `$checkIds` limits the probes to the ones those checks read, for the
     * `health` runtime type (#188), which runs one check at a time every
     * minute; the other probe fields keep their "not probed" value (null,
     * empty or false). The configuration reads always run: they are cheap.
     * Null probes everything, for a full `excubia:check`.
     *
     * @param  list<string>|null  $checkIds
     */
    public function collect(Application $application, ?DateTimeImmutable $now = null, ?ExcubiaConfigurationData $configuration = null, ?array $checkIds = null): LaravelPostureData
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $configuration ??= ExcubiaConfigurationData::defaults();
        $isProbed = static fn (string ...$probedCheckIds): bool => $checkIds === null || array_intersect($probedCheckIds, $checkIds) !== [];
        $heartbeat = $isProbed('laravel-scheduler-heartbeat') ? $this->schedulerHeartbeat($application) : ['isReadable' => false, 'at' => null];
        $failedJobs = $isProbed('laravel-failed-jobs') ? $this->failedJobs($application) : ['count' => null, 'oldestFailedAt' => null];
        $config = $application->make('config');
        $applicationKey = $config->get('app.key');
        $cacheProbe = $isProbed('laravel-cache') ? $this->cacheProbe($application, $config) : ['store' => null, 'isWorking' => null];
        $queueBacklogs = $isProbed('laravel-queue-backlog', 'laravel-queue-workers') ? $this->queueBacklogs($application, $config, $configuration->queueBacklogQueueNames, $now) : null;
        $isHorizonInstalled = $isProbed('laravel-queue-workers') && $this->isHorizonInstalled($application);
        $isBackupPackageInstalled = $isProbed('laravel-backups') && class_exists(self::BACKUP_DESTINATION);
        $databaseStatistics = $isProbed('laravel-database-connections', 'laravel-database-size')
            ? $this->defaultDatabaseStatistics($application, $config)
            : ['connection' => null, 'driver' => null, 'connectionCount' => null, 'size' => null];

        return new LaravelPostureData(
            environment: $application->environment(),
            isDebugModeEnabled: (bool) $config->get('app.debug'),
            isConfigurationCached: $application->configurationIsCached(),
            areRoutesCached: $application->routesAreCached(),
            areEventsCached: $application->eventsAreCached(),
            isMaintenanceModeActive: $application->isDownForMaintenance(),
            pendingMigrations: $isProbed('laravel-pending-migrations') ? $this->pendingMigrations($application) : null,
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
            databaseConnectionReachability: $isProbed('laravel-database') ? $this->databaseConnectionReachability($application, $config, $configuration->databaseConnectionNames) : [],
            cacheStoreName: $cacheProbe['store'],
            isCacheStoreWorking: $cacheProbe['isWorking'],
            unwritableDirectoryNames: $isProbed('laravel-storage') ? $this->unwritableDirectoryNames($application) : [],
            diskUsedPercent: $isProbed('laravel-disk-space') ? $this->diskSpace->usedPercent($application->storagePath()) : null,
            queueBacklogs: $queueBacklogs,
            queueWorkerHeartbeats: $isProbed('laravel-queue-workers') ? $this->queueWorkerHeartbeats($application, $config, $queueBacklogs) : null,
            isHorizonInstalled: $isHorizonInstalled,
            horizonStatus: $isHorizonInstalled ? $this->horizonStatus($application) : null,
            scheduledTasks: $checkIds === null ? $this->scheduledTasks($application, $configuration->schedulerLateAfterMinutes) : null,
            isBackupPackageInstalled: $isBackupPackageInstalled,
            backupDestinations: $isBackupPackageInstalled ? $this->backupDestinations($config) : null,
            defaultDatabaseConnectionName: $databaseStatistics['connection'],
            defaultDatabaseDriverName: $databaseStatistics['driver'],
            databaseConnectionCount: $databaseStatistics['connectionCount'],
            databaseSize: $databaseStatistics['size'],
            redisMemory: $isProbed('laravel-redis-memory') ? $this->redisMemory($application, $config, $configuration->redisMemoryConnectionNames) : null,
            phpRuntime: $isProbed('php-opcache', 'php-production-ini') ? $this->phpRuntime($application, $now) : null,
            octane: $isProbed('laravel-octane-config') ? $this->octane($config) : null,
            reverb: $isProbed('laravel-reverb') ? $this->reverb($config) : null,
            cashier: $isProbed('laravel-cashier') ? $this->cashier($application, $config) : null,
        );
    }

    /**
     * Octane's server and `max_requests` (#213), or null when Octane is not
     * installed: its event classes are missing, or its `octane`
     * configuration is (its service provider always merges it).
     *
     * @return array{server: string, maxRequests: int|null}|null
     */
    private function octane(Repository $config): ?array
    {
        $octane = $config->get('octane');

        if (! class_exists(self::OCTANE_WORKER_STARTING) || ! is_array($octane)) {
            return null;
        }

        $server = $octane['server'] ?? null;
        $maxRequests = $octane['max_requests'] ?? null;

        return [
            'server' => in_array($server, RuntimeCollectorService::OCTANE_SERVERS, true) ? $server : 'unknown',
            'maxRequests' => is_int($maxRequests) || (is_string($maxRequests) && preg_match('/^-?\d+$/', $maxRequests) === 1) ? (int) $maxRequests : null,
        ];
    }

    /**
     * Laravel Cashier's webhook and key posture (#217), one entry per
     * installed package, or null when none is: its class is missing, or its
     * `cashier` configuration is (its service provider always merges it).
     * Stripe's secret is `cashier.webhook.secret` and its keys
     * `cashier.secret` and `cashier.key`; Paddle's are
     * `cashier.webhook_secret`, `cashier.api_key`, `cashier.client_side_token`
     * and `cashier.sandbox`. Only booleans leave this method.
     *
     * @return list<array{provider: string, isWebhookSecretConfigured: bool, isWebhookRouteRegistered: bool, hasTestKeys: bool}>|null
     */
    private function cashier(Application $application, Repository $config): ?array
    {
        $cashier = $config->get('cashier');

        if (! is_array($cashier)) {
            return null;
        }

        $isFilled = static fn (mixed $value): bool => is_string($value) && trim($value) !== '';
        $hasPrefix = static fn (mixed $value, array $prefixes): bool => is_string($value) && array_filter($prefixes, static fn (string $prefix): bool => str_starts_with($value, $prefix)) !== [];
        $packages = [];

        foreach (self::CASHIER_PACKAGES as $provider => [$cashierClass, $controllerClass]) {
            if (! class_exists($cashierClass)) {
                continue;
            }

            $webhook = is_array($cashier['webhook'] ?? null) ? $cashier['webhook'] : [];

            $packages[] = [
                'provider' => $provider,
                'isWebhookSecretConfigured' => $isFilled($provider === 'stripe' ? ($webhook['secret'] ?? null) : ($cashier['webhook_secret'] ?? null)),
                'isWebhookRouteRegistered' => $this->hasRouteTo($application, $controllerClass),
                'hasTestKeys' => $provider === 'stripe'
                    ? $hasPrefix($cashier['secret'] ?? null, self::STRIPE_TEST_KEY_PREFIXES) || $hasPrefix($cashier['key'] ?? null, self::STRIPE_TEST_KEY_PREFIXES)
                    : filter_var($cashier['sandbox'] ?? false, FILTER_VALIDATE_BOOL) || $hasPrefix($cashier['api_key'] ?? null, self::PADDLE_TEST_KEY_PREFIXES) || $hasPrefix($cashier['client_side_token'] ?? null, self::PADDLE_TEST_KEY_PREFIXES),
            ];
        }

        return $packages === [] ? null : $packages;
    }

    /**
     * Whether a registered route uses this controller or a subclass of it
     * (Cashier's docs tell apps to extend its webhook controller). False
     * when the routes cannot be read.
     */
    private function hasRouteTo(Application $application, string $controllerClass): bool
    {
        try {
            foreach ($application->make('router')->getRoutes()->getRoutes() as $route) {
                $routeController = $route->getControllerClass();

                if (is_string($routeController) && is_a($routeController, $controllerClass, true)) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Whether the Reverb server answers (#215), or null when Reverb is not
     * installed: its event classes are missing, or its `reverb`
     * configuration is (its service provider always merges it). Only when the default broadcast connection uses the
     * `reverb` driver: a TCP connect to its `options.host` and
     * `options.port` (443, or 80 for the `http` scheme, when unset), the
     * address the app broadcasts to. The connect error is dropped: it names
     * the host.
     *
     * ponytail: a host name is resolved by the system resolver, which the
     * 2 s timeout does not cover; a broken resolver can make the check wait
     * longer.
     *
     * @return array{isBroadcastDriver: bool, isReachable: bool|null, latencyMs: float|null}|null
     */
    private function reverb(Repository $config): ?array
    {
        if (! class_exists(self::REVERB_MESSAGE_SENT) || ! is_array($config->get('reverb'))) {
            return null;
        }

        $default = $config->get('broadcasting.default');
        $connection = is_string($default) && $default !== '' ? $config->get('broadcasting.connections.'.$default) : null;

        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'reverb') {
            return ['isBroadcastDriver' => false, 'isReachable' => null, 'latencyMs' => null];
        }

        $options = is_array($connection['options'] ?? null) ? $connection['options'] : [];
        $host = $options['host'] ?? null;
        $port = $options['port'] ?? null;
        $port = is_int($port) || (is_string($port) && ctype_digit($port)) ? (int) $port : (($options['scheme'] ?? 'https') === 'http' ? 80 : 443);

        if (! is_string($host) || $host === '' || $port < 1 || $port > 65535) {
            return ['isBroadcastDriver' => true, 'isReachable' => null, 'latencyMs' => null];
        }

        $address = str_contains($host, ':') && ! str_starts_with($host, '[') ? '['.$host.']' : $host;
        $startedAt = hrtime(true);
        $socket = @stream_socket_client('tcp://'.$address.':'.$port, $errorCode, $errorMessage, self::REVERB_CONNECT_TIMEOUT_SECONDS);
        $latencyMs = round((hrtime(true) - $startedAt) / 1e6, 1);

        if ($socket === false) {
            return ['isBroadcastDriver' => true, 'isReachable' => false, 'latencyMs' => null];
        }

        fclose($socket);

        return ['isBroadcastDriver' => true, 'isReachable' => true, 'latencyMs' => $latencyMs];
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
     * Runs `select 1`. An unknown name or a failing query is unreachable.
     */
    private function isDatabaseReachable(Application $application, Repository $config, string $name): bool
    {
        return $this->probeDatabase($application, $config, $name, static function (ConnectionInterface $connection): bool {
            $connection->select('select 1');

            return true;
        }) ?? false;
    }

    /**
     * Runs `$probe` on a connection. A connection the app has not opened yet
     * is opened with a short connect timeout (MySQL, MariaDB, PostgreSQL),
     * then closed and its configuration restored, so the probe leaves no
     * trace. Null for an unknown name or when the probe throws.
     *
     * @template TResult
     *
     * @param  Closure(ConnectionInterface): TResult  $probe
     * @return TResult|null
     */
    private function probeDatabase(Application $application, Repository $config, string $name, Closure $probe): mixed
    {
        $key = 'database.connections.'.$name;
        $settings = $config->get($key);

        if (! is_array($settings)) {
            return null;
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

            return $probe($database->connection($name));
        } catch (Throwable) {
            return null;
        } finally {
            if ($shouldLimitTimeout) {
                $application->make('db')->purge($name);
                $config->set($key, $settings);
            }
        }
    }

    /**
     * Open connections and size of the default connection (#169), through
     * `DatabaseStatisticsService`. Only the connection name, the driver
     * name, counts and megabytes are kept: the SQLite file path is read to
     * measure the file and then dropped.
     *
     * @return array{connection: string|null, driver: string|null, connectionCount: int|null, size: array{sizeMb: float, largestTables: list<array{table: string, sizeMb: float}>}|null}
     */
    private function defaultDatabaseStatistics(Application $application, Repository $config): array
    {
        $name = $config->get('database.default');
        $name = is_string($name) && $name !== '' ? $name : null;
        $driver = $name === null ? null : $config->get('database.connections.'.$name.'.driver');
        $driver = is_string($driver) && $driver !== '' ? $driver : null;
        $unmeasured = ['connection' => $name, 'driver' => $driver, 'connectionCount' => null, 'size' => null];

        if ($name === null || $driver === null || ! in_array($driver, DatabaseStatisticsService::SIZE_DRIVERS, true)) {
            return $unmeasured;
        }

        $measured = $this->probeDatabase($application, $config, $name, function (ConnectionInterface $connection) use ($application, $driver): array {
            $query = new LaravelDatabaseQueryService($connection);
            $sqliteFile = $driver === 'sqlite' ? $this->sqliteFile($application, $connection->getConfig('database')) : null;

            return [
                'connectionCount' => $this->databaseStatistics->connectionCount($driver, $query),
                'size' => $this->databaseStatistics->size($driver, $query, $sqliteFile),
            ];
        });

        return $measured === null ? $unmeasured : ['connection' => $name, 'driver' => $driver, 'connectionCount' => $measured['connectionCount'], 'size' => $measured['size']];
    }

    /**
     * The file of a SQLite connection, resolved as Laravel's connector does
     * (as written, else relative to the app); null for `:memory:` or a
     * missing file.
     */
    private function sqliteFile(Application $application, mixed $database): ?string
    {
        if (! is_string($database) || $database === '' || $database === ':memory:') {
            return null;
        }

        $file = realpath($database) ?: realpath($application->basePath($database));

        return $file === false ? null : $file;
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

    /**
     * Last `QueueWorkerHeartbeatService` heartbeat of each queue the backlog
     * probe read, on the default queue connection. Null when the backlog is
     * unknown (driver not read) or the cache store throws.
     *
     * @param  list<array{queue: string, pendingCount: int, oldestAgeMinutes: int|null}>|null  $queueBacklogs
     * @return list<array{connection: string, queue: string, lastSeenAt: DateTimeImmutable|null}>|null
     */
    private function queueWorkerHeartbeats(Application $application, Repository $config, ?array $queueBacklogs): ?array
    {
        $connection = $config->get('queue.default');

        if ($queueBacklogs === null || ! is_string($connection)) {
            return null;
        }

        try {
            $cache = $application->make('cache');
            $heartbeats = [];

            foreach ($queueBacklogs as $backlog) {
                $timestamp = $cache->get(QueueWorkerHeartbeatService::cacheKey($connection, $backlog['queue']));
                $heartbeats[] = [
                    'connection' => $connection,
                    'queue' => $backlog['queue'],
                    'lastSeenAt' => is_int($timestamp) ? new DateTimeImmutable('@'.$timestamp) : null,
                ];
            }

            return $heartbeats;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Horizon is optional: it counts as installed when its master supervisor
     * repository contract exists and is bound, and is only read through its
     * contracts.
     */
    private function isHorizonInstalled(Application $application): bool
    {
        return interface_exists(self::HORIZON_MASTER_SUPERVISOR_REPOSITORY) && $application->bound(self::HORIZON_MASTER_SUPERVISOR_REPOSITORY);
    }

    /**
     * Horizon's status as `php artisan horizon:status` reports it: `inactive`
     * without a live master supervisor, `paused` when one is paused,
     * `running` otherwise. Supervisor names drop the master name prefix
     * (`<host>-<random>:`), which carries the hostname, and only the total
     * worker process count is kept. Null when Horizon's store throws.
     *
     * @return array{status: string, processes: int, supervisors: list<string>}|null
     */
    private function horizonStatus(Application $application): ?array
    {
        try {
            $masters = (array) $application->make(self::HORIZON_MASTER_SUPERVISOR_REPOSITORY)->all();
            $supervisors = $application->bound(self::HORIZON_SUPERVISOR_REPOSITORY)
                ? (array) $application->make(self::HORIZON_SUPERVISOR_REPOSITORY)->all()
                : [];
        } catch (Throwable) {
            return null;
        }

        $status = $masters === [] ? 'inactive' : 'running';

        foreach ($masters as $master) {
            if ((((array) $master)['status'] ?? null) === 'paused') {
                $status = 'paused';
            }
        }

        $processes = 0;
        $names = [];

        foreach ($supervisors as $supervisor) {
            $supervisor = (array) $supervisor;
            $name = (string) ($supervisor['name'] ?? '');
            $separator = strpos($name, ':');
            $names[] = $separator === false ? $name : substr($name, $separator + 1);

            foreach ((array) ($supervisor['processes'] ?? []) as $count) {
                $processes += is_numeric($count) ? (int) $count : 0;
            }
        }

        $names = array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
        sort($names);

        return ['status' => $status, 'processes' => $processes, 'supervisors' => $names];
    }

    /**
     * The destinations spatie/laravel-backup monitors: every disk of each
     * `backup.monitor_backups` entry, or the disks the app backs up to
     * (`backup.backup.destination.disks`) when nothing is monitored. Only the
     * disk name is kept, never its root, bucket or credentials. Null when the
     * configuration is not a list.
     *
     * @return list<array{disk: string, isReachable: bool, count: int, newestAt: DateTimeImmutable|null, newestSizeBytes: int|null, previousSizeBytes: int|null}>|null
     */
    private function backupDestinations(Repository $config): ?array
    {
        $monitors = $config->get('backup.monitor_backups');

        if ($monitors === null || $monitors === []) {
            $monitors = [['name' => $config->get('backup.backup.name'), 'disks' => $config->get('backup.backup.destination.disks')]];
        }

        if (! is_array($monitors)) {
            return null;
        }

        $destinations = [];

        foreach ($monitors as $monitor) {
            $monitor = (array) $monitor;
            $backupName = $monitor['name'] ?? null;

            if (! is_string($backupName) || $backupName === '') {
                continue;
            }

            foreach ((array) ($monitor['disks'] ?? []) as $disk) {
                if (is_string($disk) && $disk !== '') {
                    $destinations[] = $this->backupDestination($disk, $backupName);
                }
            }
        }

        return $destinations;
    }

    /**
     * Count, newest date and the two newest sizes of one destination, through
     * `BackupDestination::create($disk, $backupName)`. Only the two newest
     * backups are sized. A destination that throws or is not reachable is
     * reported unreachable; the exception message is dropped because it can
     * carry a bucket, a host or a path.
     *
     * @return array{disk: string, isReachable: bool, count: int, newestAt: DateTimeImmutable|null, newestSizeBytes: int|null, previousSizeBytes: int|null}
     */
    private function backupDestination(string $disk, string $backupName): array
    {
        $unreachable = ['disk' => $disk, 'isReachable' => false, 'count' => 0, 'newestAt' => null, 'newestSizeBytes' => null, 'previousSizeBytes' => null];

        try {
            $destination = call_user_func([self::BACKUP_DESTINATION, 'create'], $disk, $backupName);

            if (! $destination->isReachable()) {
                return $unreachable;
            }

            $backups = [];

            foreach ($destination->backups() as $backup) {
                $backups[] = ['backup' => $backup, 'timestamp' => $backup->date()->getTimestamp()];
            }

            usort($backups, static fn (array $first, array $second): int => $second['timestamp'] <=> $first['timestamp']);

            return [
                'disk' => $disk,
                'isReachable' => true,
                'count' => count($backups),
                'newestAt' => isset($backups[0]) ? new DateTimeImmutable('@'.$backups[0]['timestamp']) : null,
                'newestSizeBytes' => isset($backups[0]) ? (int) $backups[0]['backup']->sizeInBytes() : null,
                'previousSizeBytes' => isset($backups[1]) ? (int) $backups[1]['backup']->sizeInBytes() : null,
            ];
        } catch (Throwable) {
            return $unreachable;
        }
    }

    /**
     * `INFO memory` of each Redis connection in use: the connection of the
     * default cache store, of the default queue connection and of the
     * session when their driver is `redis`, or `$configuredNames` when not
     * empty. A connection that throws or answers without `used_memory` keeps
     * a null `usedBytes`; the error is dropped because it can carry a host.
     *
     * @param  list<string>  $configuredNames
     * @return list<array{connection: string, isCacheConnection: bool, usedBytes: int|null, maxBytes: int|null, policy: string|null}>
     */
    private function redisMemory(Application $application, Repository $config, array $configuredNames): array
    {
        $cacheStore = $config->get('cache.stores.'.$config->get('cache.default'));
        $queueConnection = $config->get('queue.connections.'.$config->get('queue.default'));
        $cacheConnection = $this->redisConnectionName($cacheStore);
        $connectionNames = [
            $cacheConnection,
            $this->redisConnectionName($queueConnection),
            $config->get('session.driver') === 'redis' ? $this->redisConnectionName(['driver' => 'redis', 'connection' => $config->get('session.connection')]) : null,
        ];
        $names = $configuredNames !== [] ? $configuredNames : array_values(array_unique(array_filter($connectionNames, is_string(...))));
        $memory = [];

        foreach ($names as $name) {
            $info = $this->redisMemoryInfo($application, $name);
            $memory[] = [
                'connection' => $name,
                'isCacheConnection' => $name === $cacheConnection,
                'usedBytes' => $info['used_memory'] ?? null,
                'maxBytes' => isset($info['used_memory']) ? ($info['maxmemory'] ?? 0) : null,
                'policy' => isset($info['used_memory']) ? ($info['maxmemory_policy'] ?? null) : null,
            ];
        }

        return $memory;
    }

    /**
     * The Redis connection name of a cache store or queue connection whose
     * driver is `redis` (`default` when it names none); null otherwise.
     */
    private function redisConnectionName(mixed $settings): ?string
    {
        if (! is_array($settings) || ($settings['driver'] ?? null) !== 'redis') {
            return null;
        }

        $connection = $settings['connection'] ?? null;

        return is_string($connection) && $connection !== '' ? $connection : 'default';
    }

    /**
     * `used_memory`, `maxmemory` and `maxmemory_policy` from `INFO memory`.
     * phpredis answers a flat array, Predis one nested under the `Memory`
     * section, and a raw client the `key:value` text; all three are read.
     * Every other field is dropped, and a policy that is not a plain
     * `allkeys-lru`-style word is too.
     *
     * @return array{used_memory?: int, maxmemory?: int, maxmemory_policy?: string}
     */
    private function redisMemoryInfo(Application $application, string $name): array
    {
        try {
            $reply = $application->make('redis')->connection($name)->command('info', ['memory']);
        } catch (Throwable) {
            return [];
        }

        if (is_string($reply)) {
            $lines = preg_split('/\r?\n/', $reply) ?: [];
            $reply = [];

            foreach ($lines as $line) {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $reply[$key] = $value;
                }
            }
        }

        if (! is_array($reply)) {
            return [];
        }

        if (! isset($reply['used_memory']) && is_array($reply['Memory'] ?? null)) {
            $reply = $reply['Memory'];
        }

        $info = [];

        foreach (['used_memory', 'maxmemory'] as $key) {
            if (is_numeric($reply[$key] ?? null)) {
                $info[$key] = (int) $reply[$key];
            }
        }

        $policy = $reply['maxmemory_policy'] ?? null;

        if (is_string($policy) && preg_match('/^[a-z-]{1,32}$/', $policy) === 1) {
            $info['maxmemory_policy'] = $policy;
        }

        return $info;
    }

    /**
     * The web-SAPI snapshot the health endpoint stored, with its age; null
     * when there is none. The CLI values of this process are never used:
     * the CLI has its own ini settings, so they say nothing about the web.
     *
     * @return array{sapi: string, ageMinutes: int, isOpcacheEnabled: bool, hitRate: float|null, memoryUsedPercent: float|null, cachedScripts: int|null, isValidateTimestampsEnabled: bool, isDisplayErrorsEnabled: bool, isExposePhpEnabled: bool, isLogErrorsEnabled: bool}|null
     */
    private function phpRuntime(Application $application, DateTimeImmutable $now): ?array
    {
        $snapshot = PhpWebRuntimeSnapshotService::read($application);

        if ($snapshot === null) {
            return null;
        }

        $collectedAt = $snapshot['collectedAt'];
        unset($snapshot['collectedAt']);

        return ['sapi' => 'web', 'ageMinutes' => intdiv(max(0, $now->getTimestamp() - $collectedAt), 60), ...$snapshot];
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
     * the PHP default one, which Laravel sets from `app.timezone`. Each entry
     * carries the user's late-run grace
     * (`checks.laravel-scheduler-heartbeat.lateAfterMinutes`) for the app.
     * Null when the schedule could not be read.
     *
     * @return list<array{taskId: string, task: string, expression: string, timezone: string, withoutOverlapping: bool, onOneServer: bool, runsInMaintenanceMode: bool, environments: list<string>, lateAfterMinutes: int}>|null
     */
    private function scheduledTasks(Application $application, int $lateAfterMinutes): ?array
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
                'lateAfterMinutes' => $lateAfterMinutes,
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
