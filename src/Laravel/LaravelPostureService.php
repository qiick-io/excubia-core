<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use DateTimeImmutable;
use DateTimeZone;
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
    public function collect(Application $application, ?DateTimeImmutable $now = null): LaravelPostureData
    {
        $heartbeat = $this->schedulerHeartbeat($application);
        $failedJobs = $this->failedJobs($application);

        return new LaravelPostureData(
            environment: $application->environment(),
            isDebugModeEnabled: (bool) $application->make('config')->get('app.debug'),
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
        );
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
