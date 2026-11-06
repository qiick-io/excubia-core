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
 * Production `php.ini` directives (#172). Artisan only, skipped outside
 * production: `display_errors` on fails at high (errors reach visitors),
 * `expose_php` on warns at low (the PHP version in every response header),
 * `log_errors` off warns at low (errors are lost). One row per directive,
 * subject the directive name. Like `php-opcache` it reads only the web-SAPI
 * snapshot of the health endpoint and is skipped without one, because the
 * CLI's ini settings differ. Booleans only: never an ini file path or value.
 */
final class PhpProductionIniCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'php-production-ini';
    }

    public function name(): string
    {
        return 'Production php.ini';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'php.ini', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->environment !== 'production') {
            return CheckResultData::skipped($this->id(), $this->name(), 'php.ini', 'php.ini is checked in the production environment only.');
        }

        $runtime = $posture->phpRuntime;

        if ($runtime === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'php.ini', LaravelPostureData::PHP_WEB_RUNTIME_UNKNOWN_MESSAGE);
        }

        $source = $posture->phpRuntimeSource();
        $rules = [
            ['directive' => 'display_errors', 'isEnabled' => $runtime['isDisplayErrorsEnabled'], 'isWrong' => $runtime['isDisplayErrorsEnabled'], 'severity' => SeverityEnum::High, 'status' => FindingStatusEnum::Fail, 'message' => 'display_errors is on in production, so errors can reach visitors.', 'fixHint' => 'Set display_errors=Off in the php.ini of the web server.'],
            ['directive' => 'expose_php', 'isEnabled' => $runtime['isExposePhpEnabled'], 'isWrong' => $runtime['isExposePhpEnabled'], 'severity' => SeverityEnum::Low, 'status' => FindingStatusEnum::Warn, 'message' => 'expose_php is on in production, so responses carry the PHP version.', 'fixHint' => 'Set expose_php=Off in php.ini.'],
            ['directive' => 'log_errors', 'isEnabled' => $runtime['isLogErrorsEnabled'], 'isWrong' => ! $runtime['isLogErrorsEnabled'], 'severity' => SeverityEnum::Low, 'status' => FindingStatusEnum::Warn, 'message' => 'log_errors is off in production, so PHP errors are not logged.', 'fixHint' => 'Set log_errors=On in php.ini.'],
        ];
        $findings = [];

        foreach ($rules as $rule) {
            if (! $rule['isWrong']) {
                continue;
            }

            $findings[] = new FindingData(
                checkId: $this->id(),
                severity: $rule['severity'],
                status: $rule['status'],
                subject: $rule['directive'],
                message: $rule['message'].' '.$source,
                fixHint: $rule['fixHint'],
                evidenceUrl: 'https://www.php.net/manual/en/errorfunc.configuration.php',
                details: ['directive' => $rule['directive'], 'isEnabled' => $rule['isEnabled'], 'sapi' => $runtime['sapi'], 'ageMinutes' => $runtime['ageMinutes']],
            );
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }
}
