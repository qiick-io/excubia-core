<?php

declare(strict_types=1);

namespace Qiick\Excubia\Contracts;

use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\ProjectSnapshotData;

/**
 * One registered health check.
 *
 * Implementations inspect a project snapshot and return findings. They never
 * talk to the network themselves: data-source calls go through API services.
 * A data-source outage must become a skipped result, not an exception.
 */
interface HealthCheckContract
{
    /**
     * Stable kebab-case id used in config, --only/--skip and reports (e.g. "php-eol").
     */
    public function id(): string;

    public function name(): string;

    /**
     * Inspect the snapshot and return findings. Must never throw for data-source
     * outages: return a skipped result with the reason instead.
     *
     * The check does not write files and does not read the network itself.
     * Data-source calls go through an API service.
     */
    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData;
}
