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
 * Warns at low in `production` when the default log channel (or a channel
 * it stacks) logs at `debug`: noisy logs, and debug context can carry
 * request data. Skipped outside production.
 */
final class LaravelLogLevelCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'laravel-log-level';
    }

    public function name(): string
    {
        return 'Log level';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'logging.default', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->environment !== 'production') {
            return CheckResultData::skipped($this->id(), $this->name(), 'logging.default', 'The log level is checked in the production environment only.');
        }

        if (! $posture->isDefaultLogLevelDebug) {
            return CheckResultData::fromFindings($this->id(), $this->name(), []);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::Low,
                status: FindingStatusEnum::Warn,
                subject: 'logging.default',
                message: 'The default log channel logs at debug level in production.',
                fixHint: 'Set LOG_LEVEL to info or higher, then run php artisan config:cache.',
                evidenceUrl: 'https://laravel.com/docs/logging#log-levels',
            ),
        ]);
    }
}
