<?php

declare(strict_types=1);

namespace Qiick\Excubia\Enums;

/**
 * Outcome of one finding. Pass and skipped never change the exit code.
 * Warn and fail do, once their severity reaches the configured threshold.
 */
enum FindingStatusEnum: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skipped = 'skipped';

    /**
     * Rank of a `health` runtime sample's status (#188) when two samples of
     * one check in one minute merge: the higher rank wins. Fail outranks
     * warn, warn outranks pass, and pass outranks skipped, because a skipped
     * sample carries no reading and must never hide one. This differs from
     * the report rollup (CheckResultData), where skipped outranks pass.
     */
    public function sampleRank(): int
    {
        return match ($this) {
            self::Fail => 4,
            self::Warn => 3,
            self::Pass => 2,
            self::Skipped => 1,
        };
    }
}
