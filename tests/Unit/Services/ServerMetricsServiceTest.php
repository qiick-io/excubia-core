<?php

declare(strict_types=1);

use Qiick\Excubia\Services\ServerMetricsService;
use Qiick\Excubia\Tests\Support\FakeServerDisk;
use Qiick\Excubia\Tests\Support\FakeServerResources;

it('builds one server aggregate for the minute with load, cores, memory in MiB and disk in GiB', function () {
    $disk = new FakeServerDisk;
    $aggregate = (new ServerMetricsService(new FakeServerResources, $disk))->aggregate('web-1', '/srv/app/storage', 1.0, 1793700125);

    expect($aggregate)->toBe([
        'type' => 'server',
        'bucket' => '2026-11-03T10:02:00Z',
        'sampleRate' => 1.0,
        'server' => 'web-1',
        'count' => 1,
        'errorCount' => 0,
        'loadAverage1' => 0.52,
        'loadAverage5' => 0.5,
        'loadAverage15' => 1.0,
        'cpuCores' => 4,
        'memoryUsedMb' => 2048,
        'memoryTotalMb' => 8192,
        'diskUsedGb' => 30.0,
        'diskTotalGb' => 80.0,
    ])
        ->and($disk->measuredDirectory)->toBe('/srv/app/storage')
        ->and(json_encode($aggregate, JSON_THROW_ON_ERROR))->not->toContain('/srv/app');
});

it('reports null for every value a platform does not expose', function () {
    $aggregate = (new ServerMetricsService(new FakeServerResources(null, null, null), new FakeServerDisk(null)))->aggregate('web-1', '/tmp', 0.5, 0);

    expect($aggregate)->toMatchArray([
        'sampleRate' => 0.5,
        'loadAverage1' => null,
        'loadAverage5' => null,
        'loadAverage15' => null,
        'cpuCores' => null,
        'memoryUsedMb' => null,
        'memoryTotalMb' => null,
        'diskUsedGb' => null,
        'diskTotalGb' => null,
    ]);
});

it('identifies the server by its configured name, else by a stable hash that never holds the hostname', function () {
    $hashed = ServerMetricsService::serverIdentity(null, 'db-primary.internal.example', 'base64:secret-app-key');

    expect(ServerMetricsService::serverIdentity('web-1', 'db-primary.internal.example', 'base64:secret-app-key'))->toBe('web-1')
        ->and($hashed)->toMatch('/^[0-9a-f]{12}$/')
        ->and($hashed)->toBe(substr(hash('sha256', 'db-primary.internal.example'.hash('sha256', 'base64:secret-app-key')), 0, 12))
        ->and(ServerMetricsService::serverIdentity(null, 'db-primary.internal.example', 'base64:secret-app-key'))->toBe($hashed)
        ->and(ServerMetricsService::serverIdentity(null, 'db-replica.internal.example', 'base64:secret-app-key'))->not->toBe($hashed)
        ->and(ServerMetricsService::serverIdentity(null, 'db-primary.internal.example', 'base64:other-key'))->not->toBe($hashed)
        ->and($hashed)->not->toContain('db-primary');
});
