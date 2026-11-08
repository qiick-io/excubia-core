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
 * Laravel Octane's worker settings (#213). Artisan only, and skipped when
 * laravel/octane is not installed (it is not a dependency).
 *
 * Always one `pass` row at info with the server type (`octane.server`). In
 * production, `octane.max_requests` unset warns at low (the limit then
 * depends on the `--max-requests` flag of `octane:start`, 500 by default)
 * and 0 or less warns at medium: Octane keeps every worker forever, so a
 * memory leak or stale state builds up until it crashes. Names and
 * integers only.
 */
final class LaravelOctaneConfigCheck implements HealthCheckContract
{
    private const EVIDENCE_URL = 'https://laravel.com/docs/octane#managing-memory-leaks';

    public function id(): string
    {
        return 'laravel-octane-config';
    }

    public function name(): string
    {
        return 'Octane configuration';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'octane', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->octane === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'octane', 'laravel/octane is not installed.');
        }

        $server = $posture->octane['server'];
        $maxRequests = $posture->octane['maxRequests'];
        $details = ['server' => $server, 'maxRequests' => $maxRequests];
        $findings = [
            $this->finding(SeverityEnum::Info, FindingStatusEnum::Pass, 'octane.server', 'Octane runs on '.$server.'.', '', null, $details),
        ];

        if ($posture->environment === 'production' && ($maxRequests === null || $maxRequests <= 0)) {
            $findings[] = $maxRequests === null
                ? $this->finding(SeverityEnum::Low, FindingStatusEnum::Warn, 'octane.max_requests', 'octane.max_requests is not set: how many requests a worker handles before it restarts depends on the --max-requests flag of octane:start (500 by default).', 'Set max_requests in config/octane.php (500 is a good start), so every deployment restarts its workers.', self::EVIDENCE_URL, $details)
                : $this->finding(SeverityEnum::Medium, FindingStatusEnum::Warn, 'octane.max_requests', 'octane.max_requests is '.$maxRequests.': Octane workers never restart, so a memory leak or stale state builds up until the worker crashes.', 'Set max_requests in config/octane.php to a positive number, such as 500.', self::EVIDENCE_URL, $details);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    /**
     * @param  array{server: string, maxRequests: int|null}  $details
     */
    private function finding(SeverityEnum $severity, FindingStatusEnum $status, string $subject, string $message, string $fixHint, ?string $evidenceUrl, array $details): FindingData
    {
        return new FindingData(
            checkId: $this->id(),
            severity: $severity,
            status: $status,
            subject: $subject,
            message: $message,
            fixHint: $fixHint,
            evidenceUrl: $evidenceUrl,
            details: $details,
        );
    }
}
