<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

use DateTimeImmutable;
use Qiick\Excubia\Services\CalendarDateService;

/**
 * One product cycle from endoflife.date.
 *
 * `securitySupportEndsOn` and `activeSupportEndsOn` are calendar dates when
 * the API sent one. False from the API is stored as null, which means that
 * support has not been given an end date.
 */
final readonly class EndOfLifeCycleData
{
    public function __construct(
        public string $cycle,
        public ?string $releaseDate,
        public ?string $securitySupportEndsOn,
        public ?string $activeSupportEndsOn,
        public bool $isLongTermSupport,
    ) {}

    /**
     * Security-support end as midnight UTC, or null when the API omitted it.
     */
    public function securitySupportEndDate(): ?DateTimeImmutable
    {
        return (new CalendarDateService)->parseUtcDate($this->securitySupportEndsOn);
    }

    /**
     * Active/bugfix end as midnight UTC, or null when the API omitted it.
     */
    public function activeSupportEndDate(): ?DateTimeImmutable
    {
        return (new CalendarDateService)->parseUtcDate($this->activeSupportEndsOn);
    }
}
