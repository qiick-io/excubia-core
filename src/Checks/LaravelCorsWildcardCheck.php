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
 * requests); a `*` origin without credentials passes, as it is Laravel's stock
 * config. Skipped outside production.
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

        // A bare * without credentials is Laravel's stock config and safe for public APIs (#139).
        if (! $posture->hasCorsWildcardOrigin || ! $posture->isCorsCredentialSupportEnabled) {
            return CheckResultData::fromFindings($this->id(), $this->name(), []);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::High,
                status: FindingStatusEnum::Fail,
                subject: 'cors.allowed_origins',
                message: 'cors.allowed_origins contains * while cors.supports_credentials is true.',
                fixHint: 'List the allowed origins in config/cors.php (php artisan config:publish cors) instead of *.',
                evidenceUrl: 'https://laravel.com/docs/routing#cors',
                details: ['hasCredentials' => true],
            ),
        ]);
    }
}
