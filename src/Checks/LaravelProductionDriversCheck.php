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
 * Warns at medium for each default driver meant for local work, which runs
 * jobs inline or loses cache entries, sessions or mail in `production`:
 * queue `sync`, cache `array` or `null`, session `array`, mail `log` or
 * `array`. One row per component (subjects `queue-driver`, `cache-driver`,
 * `session-driver`, `mail-driver`). Only driver names from these lists are
 * reported. Skipped outside production.
 */
final class LaravelProductionDriversCheck implements HealthCheckContract
{
    /**
     * @var array<string, array{drivers: list<string>, variable: string, documentation: string}>
     */
    private const DEVELOPMENT_DRIVERS = [
        'queue' => ['drivers' => ['sync'], 'variable' => 'QUEUE_CONNECTION', 'documentation' => 'https://laravel.com/docs/queues#driver-prerequisites'],
        'cache' => ['drivers' => ['array', 'null'], 'variable' => 'CACHE_STORE', 'documentation' => 'https://laravel.com/docs/cache#configuration'],
        'session' => ['drivers' => ['array'], 'variable' => 'SESSION_DRIVER', 'documentation' => 'https://laravel.com/docs/session#configuration'],
        'mail' => ['drivers' => ['log', 'array'], 'variable' => 'MAIL_MAILER', 'documentation' => 'https://laravel.com/docs/mail#configuration'],
    ];

    public function id(): string
    {
        return 'laravel-production-drivers';
    }

    public function name(): string
    {
        return 'Production drivers';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'production-drivers', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->environment !== 'production') {
            return CheckResultData::skipped($this->id(), $this->name(), 'production-drivers', 'Drivers are checked in the production environment only.');
        }

        $findings = [];

        foreach (self::DEVELOPMENT_DRIVERS as $component => $rule) {
            $driver = $posture->driverNames[$component] ?? null;

            if ($driver === null || ! in_array($driver, $rule['drivers'], true)) {
                continue;
            }

            $findings[] = new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::Medium,
                status: FindingStatusEnum::Warn,
                subject: $component.'-driver',
                message: 'The '.$component.' driver is '.$driver.' in production.',
                fixHint: 'Set '.$rule['variable'].' to a production driver, then run php artisan config:cache.',
                evidenceUrl: $rule['documentation'],
                details: ['component' => $component, 'driver' => $driver],
            );
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }
}
