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

    /**
     * The `details` of an end-of-life finding: the matched cycle and its
     * support end dates as `Y-m-d`, null when there is no end date.
     *
     * @return array{cycle: string, securitySupportEndsOn: string|null, activeSupportEndsOn: string|null}
     */
    public function findingDetails(): array
    {
        return [
            'cycle' => $this->cycle,
            'securitySupportEndsOn' => $this->securitySupportEndDate()?->format('Y-m-d'),
            'activeSupportEndsOn' => $this->activeSupportEndDate()?->format('Y-m-d'),
        ];
    }
}
