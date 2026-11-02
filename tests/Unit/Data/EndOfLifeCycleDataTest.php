<?php

declare(strict_types=1);

use Qiick\Excubia\Data\EndOfLifeCycleData;

it('exposes security and active support end dates as UTC midnight', function () {
    $cycle = new EndOfLifeCycleData(
        cycle: '8.1',
        releaseDate: '2021-11-25',
        securitySupportEndsOn: '2025-12-31',
        activeSupportEndsOn: '2023-11-25',
        isLongTermSupport: false,
    );

    expect($cycle->securitySupportEndDate()?->format('Y-m-d'))->toBe('2025-12-31')
        ->and($cycle->activeSupportEndDate()?->format('Y-m-d'))->toBe('2023-11-25');
});

it('returns null end dates when the API omitted them', function () {
    $cycle = new EndOfLifeCycleData('99', null, null, null, false);

    expect($cycle->securitySupportEndDate())->toBeNull()
        ->and($cycle->activeSupportEndDate())->toBeNull();
});
