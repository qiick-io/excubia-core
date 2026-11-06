<?php

declare(strict_types=1);

use Qiick\Excubia\Enums\RuntimeTypeEnum;
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

it('adds up total repeats and keeps the larger max repeats as integers when duplicate queries merge', function () {
    $duplicate = static fn (int $repeats): array => [
        'type' => 'duplicateQuery',
        'bucket' => '2026-11-03T10:15:00Z',
        'sampleRate' => 1.0,
        'origin' => 'request',
        'name' => 'orders.index',
        'sql' => 'select * from "orders" where "id" in (…)',
        'connection' => 'mysql',
        'count' => 1,
        'errorCount' => 0,
        'totalRepeats' => $repeats,
        'maxRepeats' => $repeats,
    ];
    $buffer = new RuntimeBufferService(temporaryProject([]), 65536);
    $buffer->append([$duplicate(10), $duplicate(6)]);
    $buffer->append([$duplicate(8)]);

    $aggregates = $buffer->read($buffer->claimBatches()[0]['path']);

    expect($aggregates)->toHaveCount(1)
        ->and($aggregates[0]['count'])->toBe(3)
        ->and($aggregates[0]['totalRepeats'])->toBe(24)
        ->and($aggregates[0]['maxRepeats'])->toBe(10);
});

it('keeps the larger reading of each server gauge per server and bucket, whatever the order, and null only when both are null', function () {
    $sample = static fn (string $server, ?float $load, ?int $memoryUsedMb, ?float $diskUsedGb): array => [
        'type' => 'server',
        'bucket' => '2026-11-03T10:15:00Z',
        'sampleRate' => 1.0,
        'server' => $server,
        'count' => 1,
        'errorCount' => 0,
        'loadAverage1' => $load,
        'loadAverage5' => $load,
        'loadAverage15' => null,
        'cpuCores' => 4,
        'memoryUsedMb' => $memoryUsedMb,
        'memoryTotalMb' => 7859,
        'diskUsedGb' => $diskUsedGb,
        'diskTotalGb' => 80.0,
    ];
    $buffer = new RuntimeBufferService(temporaryProject([]), 65536);
    $buffer->append([$sample('web-1', 0.5, null, 41.25), $sample('web-1', 2.75, 2100, 40.0), $sample('web-1', 1.0, 1900, null), $sample('web-2', 0.1, 900, 12.0)]);

    $aggregates = $buffer->read($buffer->claimBatches()[0]['path']);
    $reversed = RuntimeBufferService::merge(array_reverse([$sample('web-1', 0.5, null, 41.25), $sample('web-1', 2.75, 2100, 40.0), $sample('web-1', 1.0, 1900, null)]));

    expect($aggregates)->toHaveCount(2)
        ->and($aggregates[0])->toMatchArray(['server' => 'web-1', 'count' => 3, 'loadAverage1' => 2.75, 'loadAverage5' => 2.75, 'cpuCores' => 4, 'memoryUsedMb' => 2100, 'memoryTotalMb' => 7859, 'diskUsedGb' => 41.25, 'diskTotalGb' => 80.0])
        ->and($aggregates[0]['loadAverage15'])->toBeNull()
        ->and($aggregates[1])->toMatchArray(['server' => 'web-2', 'count' => 1, 'memoryUsedMb' => 900])
        ->and($reversed)->toEqual([$aggregates[0]]);
});

it('merges isContainer with OR and the cpuLimit gauge by the larger value, also with entries written before #173, without making them dimensions', function () {
    $sample = static fn (array $fields): array => [
        'type' => 'server',
        'bucket' => '2026-11-03T10:15:00Z',
        'sampleRate' => 1.0,
        'server' => 'web-1',
        'count' => 1,
        'errorCount' => 0,
        'cpuCores' => 4,
        ...$fields,
    ];
    $buffer = new RuntimeBufferService(temporaryProject([]), 65536);
    $buffer->append([$sample(['cpuLimit' => null, 'isContainer' => false]), $sample(['cpuLimit' => 1.5, 'isContainer' => true]), $sample([])]);

    $aggregates = $buffer->read($buffer->claimBatches()[0]['path']);

    expect($aggregates)->toHaveCount(1)
        ->and($aggregates[0])->toMatchArray(['count' => 3, 'cpuLimit' => 1.5, 'isContainer' => true])
        ->and(RuntimeBufferService::merge([$sample(['isContainer' => false]), $sample([])]))->toEqual([[...$sample(['isContainer' => false]), 'count' => 2]])
        ->and(RuntimeBufferService::mergeKey($sample(['isContainer' => true])))->toBe(RuntimeBufferService::mergeKey($sample([])));
});

it('skips lines whose isContainer is not a boolean', function () {
    $buffer = new RuntimeBufferService(temporaryProject([]), 65536);
    $buffer->append([bufferedAggregate('home'), [...bufferedAggregate('broken'), 'isContainer' => 'yes']]);

    expect(array_column($buffer->read($buffer->claimBatches()[0]['path']), 'route'))->toBe(['home']);
});

it('skips lines whose server gauge is not a number or null', function () {
    $buffer = new RuntimeBufferService(temporaryProject([]), 65536);
    $buffer->append([bufferedAggregate('home'), [...bufferedAggregate('broken'), 'cpuCores' => '4']]);

    expect(array_column($buffer->read($buffer->claimBatches()[0]['path']), 'route'))->toBe(['home']);
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

it('adds histograms element by element across appends and batches, and excludes them from the merge key', function () {
    $histogram = static function (array $counts): array {
        $buckets = array_fill(0, count(RuntimeTypeEnum::HISTOGRAM_BOUNDS_MS) + 1, 0);

        foreach ($counts as $index => $count) {
            $buckets[$index] = $count;
        }

        return $buckets;
    };
    $buffer = new RuntimeBufferService(temporaryProject([]), 65536);
    $buffer->append([[...bufferedAggregate('home', 2), 'histogram' => $histogram([0 => 1, 3 => 1])]]);
    $buffer->append([[...bufferedAggregate('home', 1), 'histogram' => $histogram([3 => 1])]]);
    $first = $buffer->read($buffer->claimBatches()[0]['path']);

    $buffer->append([[...bufferedAggregate('home', 3), 'histogram' => $histogram([11 => 3])]]);
    $batches = $buffer->claimBatches();
    $acrossBatches = RuntimeBufferService::merge([...$buffer->read($batches[0]['path']), ...$buffer->read($batches[1]['path'])]);

    expect($first)->toHaveCount(1)
        ->and($first[0]['count'])->toBe(3)
        ->and($first[0]['histogram'])->toBe($histogram([0 => 1, 3 => 2]))
        ->and(array_sum($first[0]['histogram']))->toBe($first[0]['count'])
        ->and($acrossBatches)->toHaveCount(1)
        ->and($acrossBatches[0]['count'])->toBe(6)
        ->and($acrossBatches[0]['histogram'])->toBe($histogram([0 => 1, 3 => 2, 11 => 3]))
        ->and(array_sum($acrossBatches[0]['histogram']))->toBe($acrossBatches[0]['count']);
});

it('drops the histogram when only one side has one or the bucket counts differ, so it never disagrees with count', function (?array $other) {
    $with = [...bufferedAggregate('home', 1), 'histogram' => [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]];
    $without = bufferedAggregate('home', 1);

    if ($other !== null) {
        $without['histogram'] = $other;
    }

    $forward = RuntimeBufferService::merge([$with, $without]);
    $backward = RuntimeBufferService::merge([$without, $with]);

    expect($forward)->toHaveCount(1)
        ->and($forward[0]['count'])->toBe(2)
        ->and($forward[0])->not->toHaveKey('histogram')
        ->and($backward)->toHaveCount(1)
        ->and($backward[0])->not->toHaveKey('histogram');
})->with([
    'missing' => [null],
    'other length' => [[1, 0, 0]],
]);

it('skips lines whose histogram is not a list of counts', function (mixed $histogram) {
    $directory = temporaryProject([]);
    $buffer = new RuntimeBufferService($directory, 65536);
    $buffer->append([bufferedAggregate('home'), [...bufferedAggregate('broken'), 'histogram' => $histogram]]);

    expect(array_column($buffer->read($buffer->claimBatches()[0]['path']), 'route'))->toBe(['home']);
})->with([
    'a number' => [3],
    'empty' => [[]],
    'negative count' => [[1, -1]],
    'decimal count' => [[0.5, 0.5]],
    'an object' => [['first' => 1]],
]);

it('fails with a configuration error when the directory cannot be created', function () {
    $file = temporaryProject(['blocker' => 'x']).'/blocker';

    expect(fn () => (new RuntimeBufferService($file.'/runtime', 65536))->append([bufferedAggregate('home')]))
        ->toThrow(InvalidConfigurationException::class, 'could not be created');
});
