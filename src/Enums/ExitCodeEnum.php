<?php

declare(strict_types=1);

namespace Qiick\Excubia\Enums;

/**
 * Process status for `vendor/bin/excubia`. `check` does not return 3:
 * `--strict` is still a proposal, and a data-source miss becomes a skipped
 * check. `push` returns 3 when excubia-app rejects the token or does not
 * accept the report.
 */
enum ExitCodeEnum: int
{
    case NoFindingsAtOrAboveFailOn = 0;
    case FindingsAtOrAboveFailOn = 1;
    case UsageOrConfigurationError = 2;
    case DataSourceFailure = 3;
}
