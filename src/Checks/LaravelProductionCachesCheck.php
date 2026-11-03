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
 * Warns at low for each of the config, route and event caches that is
 * missing in `production`. One row per cache, so an ignore rule can accept
 * one of them (subjects `config-cache`, `route-cache`, `event-cache`).
 * Outside production the check is skipped: caches are a deploy step.
 */
final class LaravelProductionCachesCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'laravel-production-caches';
    }

    public function name(): string
    {
        return 'Production caches';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'production-caches', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->environment !== 'production') {
            return CheckResultData::skipped($this->id(), $this->name(), 'production-caches', 'Caches are checked in the production environment only.');
        }

        $caches = [
            'config-cache' => ['isCached' => $posture->isConfigurationCached, 'label' => 'Configuration', 'command' => 'config:cache'],
            'route-cache' => ['isCached' => $posture->areRoutesCached, 'label' => 'Route', 'command' => 'route:cache'],
            'event-cache' => ['isCached' => $posture->areEventsCached, 'label' => 'Event', 'command' => 'event:cache'],
        ];
        $findings = [];

        foreach ($caches as $subject => $cache) {
            if ($cache['isCached']) {
                continue;
            }

            $findings[] = new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::Low,
                status: FindingStatusEnum::Warn,
                subject: $subject,
                message: $cache['label'].' cache is missing in production.',
                fixHint: 'Run php artisan '.$cache['command'].' (or php artisan optimize) on deploy.',
                evidenceUrl: 'https://laravel.com/docs/deployment#optimization',
            );
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }
}
