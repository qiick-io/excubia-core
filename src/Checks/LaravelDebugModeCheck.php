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
 * Fails at high when `app.debug` is on outside the `local` and `testing`
 * environments: debug pages leak stack traces and configuration.
 */
final class LaravelDebugModeCheck implements HealthCheckContract
{
    private const DEBUG_ENVIRONMENTS = ['local', 'testing'];

    public function id(): string
    {
        return 'laravel-debug-mode';
    }

    public function name(): string
    {
        return 'Debug mode';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'app.debug', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if (! $posture->isDebugModeEnabled || in_array($posture->environment, self::DEBUG_ENVIRONMENTS, true)) {
            return CheckResultData::fromFindings($this->id(), $this->name(), []);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::High,
                status: FindingStatusEnum::Fail,
                subject: 'app.debug',
                message: 'Debug mode is on in the '.$posture->environment.' environment.',
                fixHint: 'Set APP_DEBUG=false outside local and testing, then run php artisan config:cache.',
                evidenceUrl: 'https://laravel.com/docs/configuration#debug-mode',
            ),
        ]);
    }
}
