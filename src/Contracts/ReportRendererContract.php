<?php

declare(strict_types=1);

namespace Qiick\Excubia\Contracts;

use Qiick\Excubia\Data\HealthReportData;

/**
 * Turns a finished health report into text for stdout or `--output`.
 *
 * Renderers are pure: no I/O, no network, no mutation of the report.
 */
interface ReportRendererContract
{
    /**
     * Turn a report into the text the CLI writes to stdout or `--output`.
     *
     * Rendering has no side effects and does not read the network.
     */
    public function render(HealthReportData $healthReport): string;
}
