<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Qiick\Excubia\Data\LaravelPostureData;
use Throwable;

/**
 * Reads the configuration posture of the booted app for the `laravel-*`
 * posture checks. Only the Artisan commands call it: the standalone CLI
 * cannot boot the app and must not load `illuminate/*`.
 *
 * Read-only: it asks the container, the migrator, the failed job store and
 * the cache, and writes nothing. A source that throws (no database, cache
 * store down) becomes null in the data object so the check skips; the
 * exception message is dropped because it can carry a DSN or a hostname.
 */
final class LaravelPostureService
{
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

    public function collect(Application $application, ?DateTimeImmutable $now = null): LaravelPostureData
    {
        $heartbeat = $this->schedulerHeartbeat($application);
        $failedJobs = $this->failedJobs($application);
        $config = $application->make('config');
        $applicationKey = $config->get('app.key');

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
            collectedAt: $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
            hasApplicationKey: is_string($applicationKey) && $applicationKey !== '',
            isApplicationUrlHttps: str_starts_with(strtolower((string) $config->get('app.url')), 'https://'),
            isSessionCookieSecure: (bool) $config->get('session.secure'),
            isSessionCookieHttpOnly: (bool) $config->get('session.http_only', true),
            hasCorsWildcardOrigin: in_array('*', (array) $config->get('cors.allowed_origins', []), true),
            isCorsCredentialSupportEnabled: (bool) $config->get('cors.supports_credentials'),
            driverNames: $this->driverNames($config),
            isDefaultLogLevelDebug: $this->isDefaultLogLevelDebug($config),
            enabledDevelopmentPackageNames: $this->enabledDevelopmentPackageNames($application, $config),
        );
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
