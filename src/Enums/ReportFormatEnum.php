<?php

declare(strict_types=1);

namespace Qiick\Excubia\Enums;

/**
 * Values accepted by `--format`. `sarif` is SARIF 2.1.0 for GitHub code
 * scanning (D6).
 */
enum ReportFormatEnum: string
{
    case Table = 'table';
    case Json = 'json';
    case Markdown = 'markdown';
    case Sarif = 'sarif';
}
