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
    ) {}
}
