<?php

declare(strict_types=1);

namespace Qiick\Excubia\Enums;

/**
 * Why a check was skipped. Only `DataSourceFailure` can make `--strict`
 * exit 3: the other skips are deliberate (`--offline` without a cached
 * response, a check that does not apply to the project or was disabled).
 * Checks left out with `--only` / `--skip` and rows removed by an ignore
 * rule are not in the report at all.
 */
enum SkipReasonEnum: string
{
    case DataSourceFailure = 'data-source-failure';
    case Offline = 'offline';
    case NotApplicable = 'not-applicable';
}
