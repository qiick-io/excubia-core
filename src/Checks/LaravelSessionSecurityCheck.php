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
 * Warns at medium in `production` when the session cookie is not marked
 * secure while `app.url` is https, or is readable by JavaScript. One row
 * per key (subjects `session.secure` and `session.http_only`) so an
 * ignore rule can accept one of them. Skipped outside production.
 */
final class LaravelSessionSecurityCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'laravel-session-security';
    }

    public function name(): string
    {
        return 'Session cookie security';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'session', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->environment !== 'production') {
            return CheckResultData::skipped($this->id(), $this->name(), 'session', 'Session cookies are checked in the production environment only.');
        }

        $findings = [];

        if (! $posture->isSessionCookieSecure && $posture->isApplicationUrlHttps) {
            $findings[] = $this->finding('session.secure', 'session.secure is false while app.url is https.', 'Set SESSION_SECURE_COOKIE=true so the cookie is only sent over https.');
        }

        if (! $posture->isSessionCookieHttpOnly) {
            $findings[] = $this->finding('session.http_only', 'session.http_only is false.', 'Set session.http_only to true so JavaScript cannot read the session cookie.');
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    private function finding(string $subject, string $message, string $fixHint): FindingData
    {
        return new FindingData(
            checkId: $this->id(),
            severity: SeverityEnum::Medium,
            status: FindingStatusEnum::Warn,
            subject: $subject,
            message: $message,
            fixHint: $fixHint.' Then run php artisan config:cache.',
            evidenceUrl: 'https://laravel.com/docs/session#configuration',
        );
    }
}
