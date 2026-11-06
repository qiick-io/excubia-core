<?php

declare(strict_types=1);

use Qiick\Excubia\Services\ServerResourcesService;

function serverFixtures(string $name): string
{
    return dirname(__DIR__, 2).'/Fixtures/Server/'.$name;
}

it('reads load, CPU cores and memory from /proc on Linux', function () {
    $resources = new ServerResourcesService(serverFixtures('linux'), 'Linux');

    expect($resources->loadAverages())->toBe([0.52, 0.58, 1.07])
        ->and($resources->cpuCoreCount())->toBe(4)
        ->and($resources->memory())->toBe(['usedBytes' => (8048208 - 3024104) * 1024, 'totalBytes' => 8048208 * 1024]);
});

it('falls back to free, buffers and page cache on kernels without MemAvailable, and counts ARM cpuinfo', function () {
    $resources = new ServerResourcesService(serverFixtures('linux-without-memavailable'), 'Linux');

    expect($resources->memory())->toBe(['usedBytes' => (2048000 - 512000 - 102400 - 409600) * 1024, 'totalBytes' => 2048000 * 1024])
        ->and($resources->cpuCoreCount())->toBe(2);
});

it('falls back to sys_getloadavg() on Linux when /proc/loadavg cannot be read', function () {
    $loadAverages = (new ServerResourcesService(serverFixtures('linux-without-memavailable'), 'Linux'))->loadAverages();

    expect($loadAverages)->toBeArray()->toHaveCount(3)
        ->and($loadAverages[0])->toBeFloat();
});

it('reports null CPU cores and memory on macOS without reading /proc or starting a process, and the load from sys_getloadavg()', function () {
    $resources = new ServerResourcesService(serverFixtures('linux'), 'Darwin');
    $loadAverages = $resources->loadAverages();

    expect($resources->cpuCoreCount())->toBeNull()
        ->and($resources->memory())->toBeNull()
        ->and($loadAverages)->toBeArray()->toHaveCount(3)
        ->and($loadAverages)->not->toBe([0.52, 0.58, 1.07]);
});

it('reports null when /proc is missing on Linux', function () {
    $resources = new ServerResourcesService(serverFixtures('missing'), 'Linux');

    expect($resources->cpuCoreCount())->toBeNull()
        ->and($resources->memory())->toBeNull();
});

it('parses /proc contents and rejects what it does not recognise', function () {
    expect(ServerResourcesService::parseLoadAverages("12.00 3.5 0 1/2 3\n"))->toBe([12.0, 3.5, 0.0])
        ->and(ServerResourcesService::parseLoadAverages('not a load'))->toBeNull()
        ->and(ServerResourcesService::parseCpuCoreCount("vendor_id\t: x\n"))->toBeNull()
        ->and(ServerResourcesService::parseMemory("MemFree: 10 kB\n"))->toBeNull()
        ->and(ServerResourcesService::parseMemory("MemTotal: 0 kB\nMemAvailable: 0 kB\n"))->toBeNull()
        ->and(ServerResourcesService::parseMemory("MemTotal: 100 kB\n"))->toBeNull()
        ->and(ServerResourcesService::parseMemory("MemTotal: 100 kB\nMemAvailable: 150 kB\n"))->toBe(['usedBytes' => 0, 'totalBytes' => 102400]);
});

it('reads this machine without failing', function () {
    $resources = new ServerResourcesService;
    $memory = $resources->memory();
    $cpuCoreCount = $resources->cpuCoreCount();

    expect($resources->loadAverages())->toBeArray()->toHaveCount(3);

    if (PHP_OS_FAMILY === 'Linux') {
        expect($memory['totalBytes'] ?? 0)->toBeGreaterThan(0)
            ->and($cpuCoreCount)->toBeGreaterThan(0);
    } else {
        expect($memory)->toBeNull()
            ->and($cpuCoreCount)->toBeNull();
    }
});

it('reads the memory and CPU limits of a cgroup v2 container instead of the host (#173)', function () {
    $resources = new ServerResourcesService(serverFixtures('linux'), 'Linux', serverFixtures('cgroup-v2'));

    expect($resources->memory())->toBe(['usedBytes' => 734003200 - 104857600, 'totalBytes' => 1073741824])
        ->and($resources->cpuLimit())->toBe(1.5)
        ->and($resources->cpuCoreCount())->toBe(4)
        ->and($resources->isContainer())->toBeTrue();
});

it('reads the memory and CPU limits of a cgroup v1 container, without its inactive page cache (#173)', function () {
    $resources = new ServerResourcesService(serverFixtures('linux'), 'Linux', serverFixtures('cgroup-v1'));

    expect($resources->memory())->toBe(['usedBytes' => 1048576000 - 209715200, 'totalBytes' => 2147483648])
        ->and($resources->cpuLimit())->toBe(2.0)
        ->and($resources->isContainer())->toBeTrue();
});

it('keeps the host readings for a cgroup without limits: max, -1 and the v1 unlimited value (#173)', function (string $cgroup) {
    $resources = new ServerResourcesService(serverFixtures('linux'), 'Linux', serverFixtures($cgroup));

    expect($resources->memory())->toBe(['usedBytes' => (8048208 - 3024104) * 1024, 'totalBytes' => 8048208 * 1024])
        ->and($resources->cpuLimit())->toBeNull()
        ->and($resources->isContainer())->toBeFalse();
})->with(['cgroup-v2-unlimited', 'cgroup-v1-unlimited']);

it('keeps the host readings outside a container and never reads cgroups outside Linux (#173)', function () {
    $host = new ServerResourcesService(serverFixtures('linux'), 'Linux', serverFixtures('missing'));
    $macOs = new ServerResourcesService(serverFixtures('linux'), 'Darwin', serverFixtures('cgroup-v2'));

    expect($host->memory())->toBe(['usedBytes' => (8048208 - 3024104) * 1024, 'totalBytes' => 8048208 * 1024])
        ->and($host->cpuLimit())->toBeNull()
        ->and($host->isContainer())->toBeFalse()
        ->and($macOs->memory())->toBeNull()
        ->and($macOs->cpuLimit())->toBeNull()
        ->and($macOs->isContainer())->toBeFalse();
});

it('parses cgroup limits and treats nonsense or a limit at or above the host memory as no limit (#173)', function () {
    expect(ServerResourcesService::parseCpuLimit('50000', '100000'))->toBe(0.5)
        ->and(ServerResourcesService::parseCpuLimit('max', '100000'))->toBeNull()
        ->and(ServerResourcesService::parseCpuLimit('-1', '100000'))->toBeNull()
        ->and(ServerResourcesService::parseCpuLimit('100000', '0'))->toBeNull()
        ->and(ServerResourcesService::parseCpuLimit('', ''))->toBeNull()
        ->and(ServerResourcesService::parseCgroupMemory('1000', '400', null, null))->toBe(['usedBytes' => 400, 'totalBytes' => 1000])
        ->and(ServerResourcesService::parseCgroupMemory('1000', '400', "inactive_file 500\n", 2000))->toBe(['usedBytes' => 0, 'totalBytes' => 1000])
        ->and(ServerResourcesService::parseCgroupMemory('1000', '1500', null, 2000))->toBe(['usedBytes' => 1000, 'totalBytes' => 1000])
        ->and(ServerResourcesService::parseCgroupMemory('2000', '400', null, 2000))->toBeNull()
        ->and(ServerResourcesService::parseCgroupMemory('0', '400', null, null))->toBeNull()
        ->and(ServerResourcesService::parseCgroupMemory('1000', '', null, null))->toBeNull()
        ->and(ServerResourcesService::parseCgroupMemory('max', '400', null, null))->toBeNull();
});
