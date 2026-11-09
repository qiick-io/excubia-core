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
 * Whether the Laravel Reverb server answers (#215). Artisan only, skipped
 * when laravel/reverb is not installed (it is not a dependency), when the
 * default broadcast connection does not use the `reverb` driver, and when
 * that connection names no host.
 *
 * One row: `pass` at info with the connect time, or `fail` at high when a
 * TCP connect to the configured host and port does not succeed within 2 s,
 * because every broadcast then fails. A boolean and milliseconds only:
 * never the host or the port.
 */
final class LaravelReverbCheck implements HealthCheckContract
{
    private const EVIDENCE_URL = 'https://laravel.com/docs/reverb#running-server';

    public function id(): string
    {
        return 'laravel-reverb';
    }

    public function name(): string
    {
        return 'Reverb server';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'reverb', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        $reverb = $posture->reverb;

        if ($reverb === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'reverb', 'laravel/reverb is not installed.');
        }

        if (! $reverb['isBroadcastDriver']) {
            return CheckResultData::skipped($this->id(), $this->name(), 'reverb', 'Reverb is not the default broadcast driver.');
        }

        if ($reverb['isReachable'] === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'reverb', 'The Reverb broadcast connection has no host configured.');
        }

        $details = ['isReachable' => $reverb['isReachable'], 'latencyMs' => $reverb['latencyMs']];

        $finding = $reverb['isReachable']
            ? $this->finding(SeverityEnum::Info, FindingStatusEnum::Pass, 'The Reverb server answers ('.$reverb['latencyMs'].' ms to connect).', '', null, $details)
            : $this->finding(SeverityEnum::High, FindingStatusEnum::Fail, 'The Reverb server does not answer on its configured host and port within 2 s: broadcasts fail.', 'Keep php artisan reverb:start running under a process monitor such as Supervisor, and check REVERB_HOST and REVERB_PORT.', self::EVIDENCE_URL, $details);

        return CheckResultData::fromFindings($this->id(), $this->name(), [$finding]);
    }

    /**
     * @param  array{isReachable: bool, latencyMs: float|null}  $details
     */
    private function finding(SeverityEnum $severity, FindingStatusEnum $status, string $message, string $fixHint, ?string $evidenceUrl, array $details): FindingData
    {
        return new FindingData(
            checkId: $this->id(),
            severity: $severity,
            status: $status,
            subject: 'reverb',
            message: $message,
            fixHint: $fixHint,
            evidenceUrl: $evidenceUrl,
            details: $details,
        );
    }
}
