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
 * rate under 90 % or more than 90 % of the OPcache memory used warns at low,
 * but only on the web-SAPI snapshot: a CLI process starts with an empty
 * cache, so its hit rate and memory say nothing about the web server.
 *
 * Artisan runs on the CLI SAPI, which follows `opcache.enable_cli`; when the
 * health endpoint stored a web-SAPI snapshot its values are used instead.
 * Every message says which SAPI was checked. Booleans, percentages and a
 * count only: never an ini file or script path.
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
            return CheckResultData::skipped($this->id(), $this->name(), 'opcache', 'The PHP runtime could not be read.');
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
            $hint = $runtime['sapi'] === 'cli'
                ? 'Set opcache.enable=1 for the web server (and opcache.enable_cli=1 if you want the CLI cached too), then restart PHP.'
                : 'Set opcache.enable=1, then restart PHP-FPM.';

            return CheckResultData::fromFindings($this->id(), $this->name(), $isProduction
                ? [$this->finding(SeverityEnum::Medium, 'opcache', 'OPcache is disabled in production. '.$source, $hint, $details)]
                : []);
        }

        $findings = [];

        if ($isProduction && $runtime['isValidateTimestampsEnabled']) {
            $findings[] = $this->finding(SeverityEnum::Low, 'opcache.validate_timestamps', 'opcache.validate_timestamps is on in production, so PHP checks every script for changes. '.$source, 'Set opcache.validate_timestamps=0 and reload PHP-FPM on each deploy.', $details);
        }

        if ($runtime['sapi'] === 'web' && $runtime['hitRate'] !== null && $runtime['hitRate'] < self::MIN_HIT_RATE) {
            $findings[] = $this->finding(SeverityEnum::Low, 'opcache-hit-rate', 'The OPcache hit rate is '.$runtime['hitRate'].' %, under 90 %. '.$source, 'Raise opcache.max_accelerated_files and opcache.memory_consumption.', $details);
        }

        if ($runtime['sapi'] === 'web' && $runtime['memoryUsedPercent'] !== null && $runtime['memoryUsedPercent'] > self::MAX_MEMORY_USED_PERCENT) {
            $findings[] = $this->finding(SeverityEnum::Low, 'opcache-memory', $runtime['memoryUsedPercent'].' % of the OPcache memory is used, over 90 %. '.$source, 'Raise opcache.memory_consumption.', $details);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    /**
     * @param  array{sapi: string, ageMinutes: int|null, isOpcacheEnabled: bool, hitRate: float|null, memoryUsedPercent: float|null, cachedScripts: int|null, isValidateTimestampsEnabled: bool}  $details
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
