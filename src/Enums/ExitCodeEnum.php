<?php

declare(strict_types=1);

namespace Qiick\Excubia\Enums;

/**
 * Process status for `vendor/bin/excubia`. Precedence is 2 > 1 > 3.
 * `check` returns 3 only with `--strict` (or `strict: true`), when a check
 * was skipped because a data source failed. `push` returns 3 for the same
 * reason before sending, and when excubia-app rejects the token or does
 * not accept the report.
 */
enum ExitCodeEnum: int
{
    case NoFindingsAtOrAboveFailOn = 0;
    case FindingsAtOrAboveFailOn = 1;
    case UsageOrConfigurationError = 2;
    case DataSourceFailure = 3;
}
