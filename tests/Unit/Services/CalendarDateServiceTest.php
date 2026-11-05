<?php

declare(strict_types=1);

use Qiick\Excubia\Data\EndOfLifeCycleData;
use Qiick\Excubia\Services\CalendarDateService;

it('parses a plain UTC calendar date at midnight', function () {
    $date = (new CalendarDateService)->parseUtcDate('2025-12-31');

    expect($date)->not->toBeNull()
        ->and($date->format('Y-m-d H:i:s'))->toBe('2025-12-31 00:00:00')
        ->and($date->getTimezone()->getName())->toBe('UTC');
});

it('returns null for a missing or invalid calendar date', function () {
    $calendar = new CalendarDateService;

    expect($calendar->parseUtcDate(null))->toBeNull()
        ->and($calendar->parseUtcDate('not-a-date'))->toBeNull();
});

it('keeps the time component of a Packagist timestamp', function () {
    $date = (new CalendarDateService)->parseUtcDate('2021-10-18T15:26:12+00:00');

    expect($date)->not->toBeNull()
        ->and($date->format('Y-m-d H:i:s'))->toBe('2021-10-18 15:26:12');
});

it('counts whole days until an end date', function () {
    $calendar = new CalendarDateService;
    $today = new DateTimeImmutable('2026-10-01', new DateTimeZone('UTC'));
    $end = new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC'));

    expect($calendar->daysUntil($today, $end))->toBe(30);
});

it('finds a matching end-of-life cycle by name', function () {
    $cycles = [
        new EndOfLifeCycleData('8.1', null, '2025-12-31', '2023-11-25', false),
        new EndOfLifeCycleData('8.4', null, '2028-12-31', '2026-12-31', false),
    ];

    $matched = (new CalendarDateService)->matchingCycle('8.4', $cycles);

    expect($matched)->not->toBeNull()
        ->and($matched->cycle)->toBe('8.4')
        ->and((new CalendarDateService)->matchingCycle('9.0', $cycles))->toBeNull();
});
