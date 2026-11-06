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
 * OPcache posture (#172), like Enlightn's OPcache analyzers. Artisan only.
 *
 * In production, OPcache disabled warns at medium and
 * `opcache.validate_timestamps` on warns at low. In any environment a hit
 * rate under 90 % or more than 90 % of the OPcache memory used warns at low.
 *
 * Artisan runs on the CLI SAPI, whose OPcache (`opcache.enable_cli`) and ini
 * settings differ from the web server's, so only the web-SAPI snapshot the
 * health endpoint stores is checked; without one the check is skipped. The
 * CLI values never reach a finding. Booleans, percentages and a count only:
 * never an ini file or script path.
 */
final class PhpOpcacheCheck implements HealthCheckContract
{
    private const MIN_HIT_RATE = 90.0;

    private const MAX_MEMORY_USED_PERCENT = 90.0;

    private const DOCUMENTATION = 'https://www.php.net/manual/en/opcache.configuration.php';

    public function id(): string
    {
        return 'php-opcache';
    }

    public function name(): string
    {
        return 'OPcache';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'opcache', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        $runtime = $posture->phpRuntime;

        if ($runtime === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'opcache', LaravelPostureData::PHP_WEB_RUNTIME_UNKNOWN_MESSAGE);
        }

        $isProduction = $posture->environment === 'production';
        $source = $posture->phpRuntimeSource();
        $details = [
            'sapi' => $runtime['sapi'],
            'ageMinutes' => $runtime['ageMinutes'],
            'isOpcacheEnabled' => $runtime['isOpcacheEnabled'],
            'hitRate' => $runtime['hitRate'],
            'memoryUsedPercent' => $runtime['memoryUsedPercent'],
            'cachedScripts' => $runtime['cachedScripts'],
            'isValidateTimestampsEnabled' => $runtime['isValidateTimestampsEnabled'],
        ];

        if (! $runtime['isOpcacheEnabled']) {
            return CheckResultData::fromFindings($this->id(), $this->name(), $isProduction
                ? [$this->finding(SeverityEnum::Medium, 'opcache', 'OPcache is disabled in production. '.$source, 'Set opcache.enable=1, then restart PHP-FPM.', $details)]
                : []);
        }

        $findings = [];

        if ($isProduction && $runtime['isValidateTimestampsEnabled']) {
            $findings[] = $this->finding(SeverityEnum::Low, 'opcache.validate_timestamps', 'opcache.validate_timestamps is on in production, so PHP checks every script for changes. '.$source, 'Set opcache.validate_timestamps=0 and reload PHP-FPM on each deploy.', $details);
        }

        if ($runtime['hitRate'] !== null && $runtime['hitRate'] < self::MIN_HIT_RATE) {
            $findings[] = $this->finding(SeverityEnum::Low, 'opcache-hit-rate', 'The OPcache hit rate is '.$runtime['hitRate'].' %, under 90 %. '.$source, 'Raise opcache.max_accelerated_files and opcache.memory_consumption.', $details);
        }

        if ($runtime['memoryUsedPercent'] !== null && $runtime['memoryUsedPercent'] > self::MAX_MEMORY_USED_PERCENT) {
            $findings[] = $this->finding(SeverityEnum::Low, 'opcache-memory', $runtime['memoryUsedPercent'].' % of the OPcache memory is used, over 90 %. '.$source, 'Raise opcache.memory_consumption.', $details);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    /**
     * @param  array{sapi: string, ageMinutes: int, isOpcacheEnabled: bool, hitRate: float|null, memoryUsedPercent: float|null, cachedScripts: int|null, isValidateTimestampsEnabled: bool}  $details
     */
    private function finding(SeverityEnum $severity, string $subject, string $message, string $fixHint, array $details): FindingData
    {
        return new FindingData(
            checkId: $this->id(),
            severity: $severity,
            status: FindingStatusEnum::Warn,
            subject: $subject,
            message: $message,
            fixHint: $fixHint,
            evidenceUrl: self::DOCUMENTATION,
            details: $details,
        );
    }
}
