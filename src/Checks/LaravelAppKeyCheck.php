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
 * Fails at critical when `app.key` is empty, in any environment: without a
 * key Laravel cannot encrypt cookies or sessions. Only the emptiness is
 * read, never the key.
 */
final class LaravelAppKeyCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'laravel-app-key';
    }

    public function name(): string
    {
        return 'Application key';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'app.key', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->hasApplicationKey) {
            return CheckResultData::fromFindings($this->id(), $this->name(), []);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::Critical,
                status: FindingStatusEnum::Fail,
                subject: 'app.key',
                message: 'app.key is empty.',
                fixHint: 'Run php artisan key:generate (or set APP_KEY), then php artisan config:cache.',
                evidenceUrl: 'https://laravel.com/docs/encryption#configuration',
            ),
        ]);
    }
}
