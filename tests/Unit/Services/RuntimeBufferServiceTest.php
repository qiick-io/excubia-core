<?php

declare(strict_types=1);

use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Services\RuntimeBufferService;

/**
 * @return array<string, mixed>
 */
function bufferedAggregate(string $route, int $count = 1, float $totalMs = 10.0, float $maxMs = 10.0): array
{
    return [
        'type' => 'request',
        'bucket' => '2026-11-03T10:15:00Z',
        'sampleRate' => 1.0,
        'route' => $route,
        'method' => 'GET',
        'statusClass' => '2xx',
        'count' => $count,
        'errorCount' => 0,
        'totalMs' => $totalMs,
        'maxMs' => $maxMs,
    ];
}

/**
 * Sum of every file the buffer keeps, lock file excluded.
 */
function bufferBytes(string $directory): int
{
    clearstatcache();
    $total = 0;

    foreach (glob($directory.'/*.ndjson') ?: [] as $path) {
        $total += (int) filesize($path);
    }

    return $total;
}

it('merges aggregates with the same dimensions when a batch is read', function () {
    $buffer = new RuntimeBufferService(temporaryProject([]), 65536);
    $buffer->append([bufferedAggregate('home', 1, 10.0, 10.0), bufferedAggregate('home', 2, 30.0, 25.0)]);
    $buffer->append([bufferedAggregate('about')]);

    $batches = $buffer->claimBatches();
    $aggregates = $buffer->read($batches[0]['path']);

    expect($batches)->toHaveCount(1)
        ->and($batches[0]['batchId'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($aggregates)->toHaveCount(2)
        ->and($aggregates[0]['route'])->toBe('home')
        ->and($aggregates[0]['count'])->toBe(3)
        ->and($aggregates[0]['totalMs'])->toBe(40.0)
        ->and($aggregates[0]['maxMs'])->toBe(25.0)
        ->and($aggregates[1]['route'])->toBe('about');
});

it('keeps a claimed batch and its id until it is deleted', function () {
    $buffer = new RuntimeBufferService(temporaryProject([]), 65536);
    $buffer->append([bufferedAggregate('home')]);
    $first = $buffer->claimBatches();

    $buffer->append([bufferedAggregate('later')]);
    $second = $buffer->claimBatches();

    expect($second)->toHaveCount(2)
        ->and($second[0])->toBe($first[0])
        ->and($second[1]['batchId'])->not->toBe($first[0]['batchId']);

    $buffer->delete($first[0]['path']);
    $buffer->delete($first[0]['path']);

    expect($buffer->claimBatches())->toBe([$second[1]]);
});

it('claims nothing from an empty buffer', function () {
    $buffer = new RuntimeBufferService(temporaryProject([]), 65536);
    $buffer->append([]);

    expect($buffer->claimBatches())->toBe([])
        ->and($buffer->read('/does/not/exist.ndjson'))->toBe([]);
});

it('respects the size cap by dropping the oldest entries first', function () {
    $directory = temporaryProject([]);
    $buffer = new RuntimeBufferService($directory, 4096);

    for ($i = 0; $i < 100; $i++) {
        $buffer->append([bufferedAggregate('route-'.$i)]);
    }

    $routes = array_column($buffer->read($buffer->claimBatches()[0]['path']), 'route');

    expect(bufferBytes($directory))->toBeLessThanOrEqual(4096)
        ->and($routes)->toContain('route-99')
        ->and($routes)->not->toContain('route-0')
        ->and($routes[0])->not->toBe('route-0')
        ->and(end($routes))->toBe('route-99');
});

it('drops the oldest unsent batch before newer data', function () {
    $directory = temporaryProject([]);
    $buffer = new RuntimeBufferService($directory, 4096);

    for ($i = 0; $i < 15; $i++) {
        $buffer->append([bufferedAggregate('old-'.$i)]);
    }

    $oldBatch = $buffer->claimBatches()[0];

    for ($i = 0; $i < 40; $i++) {
        $buffer->append([bufferedAggregate('new-'.$i)]);
    }

    $routes = [];

    foreach ($buffer->claimBatches() as $batch) {
        $routes = [...$routes, ...array_column($buffer->read($batch['path']), 'route')];
    }

    expect(bufferBytes($directory))->toBeLessThanOrEqual(4096)
        ->and(is_file($oldBatch['path']))->toBeFalse()
        ->and($routes)->toContain('new-39')
        ->and($routes)->not->toContain('old-0');
});

it('skips lines that are not aggregates', function () {
    $directory = temporaryProject([]);
    $buffer = new RuntimeBufferService($directory, 65536);
    $buffer->append([bufferedAggregate('home')]);
    file_put_contents($directory.'/'.RuntimeBufferService::BUFFER_FILE, "{\"type\":\"request\"\nnot json\n[]\n", FILE_APPEND);

    expect($buffer->read($buffer->claimBatches()[0]['path']))->toHaveCount(1);
});

it('fails with a configuration error when the directory cannot be created', function () {
    $file = temporaryProject(['blocker' => 'x']).'/blocker';

    expect(fn () => (new RuntimeBufferService($file.'/runtime', 65536))->append([bufferedAggregate('home')]))
        ->toThrow(InvalidConfigurationException::class, 'could not be created');
});
