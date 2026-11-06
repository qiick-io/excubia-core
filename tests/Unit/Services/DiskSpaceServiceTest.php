<?php

declare(strict_types=1);

use Qiick\Excubia\Services\DiskSpaceService;

it('measures the used share of the disk holding a directory', function () {
    $usedPercent = (new DiskSpaceService)->usedPercent(sys_get_temp_dir());

    expect($usedPercent)->toBeInt()
        ->and($usedPercent)->toBeGreaterThanOrEqual(0)
        ->and($usedPercent)->toBeLessThanOrEqual(100);
});

it('measures the used and total bytes of the disk holding a directory', function () {
    $usage = (new DiskSpaceService)->usage(sys_get_temp_dir());

    expect($usage)->toBeArray()
        ->and($usage['totalBytes'] ?? 0)->toBeGreaterThan(0)
        ->and($usage['usedBytes'] ?? -1)->toBeGreaterThanOrEqual(0)
        ->and($usage['usedBytes'] ?? 0)->toBeLessThanOrEqual($usage['totalBytes'] ?? 0)
        ->and((new DiskSpaceService)->usage(sys_get_temp_dir().'/excubia-missing-'.bin2hex(random_bytes(4))))->toBeNull();
});

it('gives null for a directory that does not exist', function () {
    expect((new DiskSpaceService)->usedPercent(sys_get_temp_dir().'/excubia-missing-'.bin2hex(random_bytes(4))))->toBeNull();
});
