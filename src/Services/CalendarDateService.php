<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Qiick\Excubia\Data\EndOfLifeCycleData;

/**
 * Shared calendar helpers for health checks.
 *
 * Checks must not each re-implement Y-m-d UTC parsing, day diffs, or cycle
 * lookup. Packagist timestamps that are not plain Y-m-d fall through to the
 * PHP date parser so release-age comparisons keep their time component.
 */
final class CalendarDateService
{
    /**
     * Parse a calendar date or timestamp into a DateTimeImmutable.
     *
     * Plain `Y-m-d` values become midnight UTC. Other strings use PHP's
     * parser (Packagist `time` values). Null or unparseable input returns null.
     */
    public function parseUtcDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

            if ($parsed === false) {
                return null;
            }

            return $parsed->setTime(0, 0);
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Whole calendar days from `$today` to `$end` (absolute day count from diff).
     */
    public function daysUntil(DateTimeImmutable $today, DateTimeImmutable $end): int
    {
        return (int) $today->diff($end)->days;
    }

    /**
     * First cycle whose `cycle` string equals `$cycleName`.
     *
     * @param  list<EndOfLifeCycleData>  $cycles
     */
    public function matchingCycle(string $cycleName, array $cycles): ?EndOfLifeCycleData
    {
        foreach ($cycles as $cycle) {
            if ($cycle->cycle === $cycleName) {
                return $cycle;
            }
        }

        return null;
    }
}
