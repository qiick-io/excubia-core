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
 * Warns at medium in `production` for each development tool (Telescope,
 * Debugbar, Ray, Pail, Clockwork) that `composer.json` lists in `require`
 * rather than `require-dev` and that the booted app has enabled. One row per
 * package, subject the package name. Skipped outside production.
 */
final class LaravelDevPackagesCheck implements HealthCheckContract
{
    public function id(): string
    {
        return 'laravel-dev-packages';
    }

    public function name(): string
    {
        return 'Development packages';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'dev-packages', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->environment !== 'production') {
            return CheckResultData::skipped($this->id(), $this->name(), 'dev-packages', 'Development packages are checked in the production environment only.');
        }

        $findings = [];

        foreach ($posture->enabledDevelopmentPackageNames as $package) {
            if (! in_array($package, $projectSnapshot->requiredPackageNames, true)) {
                continue;
            }

            $findings[] = new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::Medium,
                status: FindingStatusEnum::Warn,
                subject: $package,
                message: $package.' is in require and enabled in production.',
                fixHint: 'Move it to require-dev and deploy with composer install --no-dev, or turn it off in production.',
                evidenceUrl: 'https://getcomposer.org/doc/04-schema.md#require-dev',
                details: ['package' => $package],
            );
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }
}
