<?php

declare(strict_types=1);

use Qiick\Excubia\Services\RuntimeSamplerService;
use Qiick\Excubia\Tests\Support\FixedShareSampler;

it('takes some draws and leaves others at a fractional rate', function () {
    $sampler = new RuntimeSamplerService;
    $draws = [];

    for ($i = 0; $i < 200; $i++) {
        $draws[] = $sampler->isSampled(0.5);
    }

    // ponytail: probabilistic on purpose (production randomness); 200 equal draws at 0.5 happen with chance 2^-199.
    expect($draws)->toContain(true)
        ->and($draws)->toContain(false);
});

it('never takes a draw at rate 0', function () {
    $sampler = new RuntimeSamplerService;

    for ($i = 0; $i < 200; $i++) {
        expect($sampler->isSampled(0.0))->toBeFalse();
    }
});

it('gives tests a sampler that takes exactly the configured share', function (float $sampleRate, int $expectedCount) {
    $sampler = new FixedShareSampler;
    $takenCount = 0;

    for ($i = 0; $i < 400; $i++) {
        $takenCount += $sampler->isSampled($sampleRate) ? 1 : 0;
    }

    expect($takenCount)->toBe($expectedCount);
})->with([
    'half' => [0.5, 200],
    'a quarter' => [0.25, 100],
    'a tenth' => [0.1, 40],
]);
