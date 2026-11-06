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
 * Checks `cors.allowed_origins` in `production`: a `*` origin together with
 * `cors.supports_credentials` fails at high (any site can send credentialed
 * requests), a `*` origin alone warns at low. Skipped outside production.
 */
final class LaravelCorsWildcardCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'laravel-cors-wildcard';
    }

    public function name(): string
    {
        return 'CORS wildcard origin';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'cors.allowed_origins', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->environment !== 'production') {
            return CheckResultData::skipped($this->id(), $this->name(), 'cors.allowed_origins', 'CORS is checked in the production environment only.');
        }

        if (! $posture->hasCorsWildcardOrigin) {
            return CheckResultData::fromFindings($this->id(), $this->name(), []);
        }

        $hasCredentials = $posture->isCorsCredentialSupportEnabled;

        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData(
                checkId: $this->id(),
                severity: $hasCredentials ? SeverityEnum::High : SeverityEnum::Low,
                status: $hasCredentials ? FindingStatusEnum::Fail : FindingStatusEnum::Warn,
                subject: 'cors.allowed_origins',
                message: $hasCredentials
                    ? 'cors.allowed_origins contains * while cors.supports_credentials is true.'
                    : 'cors.allowed_origins contains *.',
                fixHint: 'List the allowed origins in config/cors.php (php artisan config:publish cors) instead of *.',
                evidenceUrl: 'https://laravel.com/docs/routing#cors',
                details: ['hasCredentials' => $hasCredentials],
            ),
        ]);
    }
}
