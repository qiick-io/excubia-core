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
 * Fails at high for `storage` and `bootstrap/cache` when they are not
 * writable directories. Only those relative names are reported.
 */
final class LaravelStorageCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'laravel-storage';
    }

    public function name(): string
    {
        return 'Writable storage';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'storage', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        $findings = [];

        foreach ($posture->unwritableDirectoryNames as $directory) {
            $findings[] = new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::High,
                status: FindingStatusEnum::Fail,
                subject: $directory,
                message: $directory.' is not writable by the PHP process.',
                fixHint: 'Give the web server and queue users write access to '.$directory.' (and its subdirectories).',
                evidenceUrl: 'https://laravel.com/docs/installation#directory-permissions',
                details: ['directory' => $directory],
            );
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }
}
