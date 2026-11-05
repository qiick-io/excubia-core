<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\LaravelPostureData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;

/**
 * Fails at medium when migrations exist that the database has not run.
 * The message names up to three migrations (names only, never paths).
 */
final class LaravelPendingMigrationsCheck implements HealthCheckContract
{
    private const NAMED_MIGRATIONS = 3;

    public function id(): string
    {
        return 'laravel-pending-migrations';
    }

    public function name(): string
    {
        return 'Pending migrations';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'migrations', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->pendingMigrations === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'migrations', 'The migration status could not be read from the database.');
        }

        $count = count($posture->pendingMigrations);

        if ($count === 0) {
            return CheckResultData::fromFindings($this->id(), $this->name(), []);
        }

        $named = implode(', ', array_slice($posture->pendingMigrations, 0, self::NAMED_MIGRATIONS));
        $more = $count > self::NAMED_MIGRATIONS ? ' and '.($count - self::NAMED_MIGRATIONS).' more' : '';

        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::Medium,
                status: FindingStatusEnum::Fail,
                subject: 'migrations',
                message: $count.' pending '.($count === 1 ? 'migration' : 'migrations').': '.$named.$more.'.',
                fixHint: 'Run php artisan migrate --force as part of the deploy.',
                evidenceUrl: 'https://laravel.com/docs/migrations#running-migrations',
                details: ['count' => $count],
            ),
        ]);
    }
}
