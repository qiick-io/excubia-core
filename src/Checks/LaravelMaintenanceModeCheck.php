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
 * Warns at medium while the app is in maintenance mode (`php artisan down`).
 */
final class LaravelMaintenanceModeCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'laravel-maintenance-mode';
    }

    public function name(): string
    {
        return 'Maintenance mode';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'maintenance-mode', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if (! $posture->isMaintenanceModeActive) {
            return CheckResultData::fromFindings($this->id(), $this->name(), []);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::Medium,
                status: FindingStatusEnum::Warn,
                subject: 'maintenance-mode',
                message: 'The application is in maintenance mode.',
                fixHint: 'Run php artisan up when the maintenance is over.',
                evidenceUrl: 'https://laravel.com/docs/configuration#maintenance-mode',
            ),
        ]);
    }
}
