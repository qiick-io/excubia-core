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

    /**
     * Formats parsed by tools, whose stdout must carry the report alone (no banner, #197).
     */
    public function isMachineReadable(): bool
    {
        return $this === self::Json || $this === self::Sarif;
    }
}
