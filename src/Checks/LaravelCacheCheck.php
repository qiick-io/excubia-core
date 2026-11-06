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
 * Fails at high when the default cache store could not write, read and
 * delete a probe key. Skipped with no default store or the `null` driver.
 */
final class LaravelCacheCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'laravel-cache';
    }

    public function name(): string
    {
        return 'Cache';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'cache', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->cacheStoreName === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'cache', 'No default cache store is configured.');
        }

        if ($posture->isCacheStoreWorking === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'cache', 'The default cache store uses the null driver, which stores nothing.');
        }

        if ($posture->isCacheStoreWorking) {
            return CheckResultData::fromFindings($this->id(), $this->name(), []);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::High,
                status: FindingStatusEnum::Fail,
                subject: $posture->cacheStoreName,
                message: 'The cache store '.$posture->cacheStoreName.' could not write, read and delete a probe key.',
                fixHint: 'Check that the cache backend is up and that config/cache.php and the CACHE_* variables of this store are right.',
                evidenceUrl: 'https://laravel.com/docs/cache#configuration',
                details: ['store' => $posture->cacheStoreName],
            ),
        ]);
    }
}
