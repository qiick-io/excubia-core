<?php

declare(strict_types=1);

use Qiick\Excubia\Services\DiskSpaceService;

it('measures the used share of the disk holding a directory', function () {
    $usedPercent = (new DiskSpaceService)->usedPercent(sys_get_temp_dir());

    expect($usedPercent)->toBeInt()
        ->and($usedPercent)->toBeGreaterThanOrEqual(0)
        ->and($usedPercent)->toBeLessThanOrEqual(100);
});

it('gives null for a directory that does not exist', function () {
    expect((new DiskSpaceService)->usedPercent(sys_get_temp_dir().'/excubia-missing-'.bin2hex(random_bytes(4))))->toBeNull();
});
