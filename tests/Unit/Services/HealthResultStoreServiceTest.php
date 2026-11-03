<?php

declare(strict_types=1);

use Qiick\Excubia\Services\HealthResultStoreService;

it('writes the payload and reads it back', function () {
    $path = temporaryProject([]).'/excubia/health.json';
    $store = new HealthResultStoreService($path);
    $payload = '{"finishedAt":1700000000,"checkResults":[]}';

    expect($store->read())->toBeNull();

    $store->write($payload);

    expect($store->read())->toBe($payload)
        ->and(glob(dirname($path).'/*.tmp'))->toBe([]);
});

it('treats a file that is not an Oh Dear document as no result', function (string $contents) {
    $path = temporaryProject(['health.json' => $contents]).'/health.json';

    expect((new HealthResultStoreService($path))->read())->toBeNull();
})->with([
    'not json' => ['{'],
    'string finishedAt' => ['{"finishedAt":"1700000000","checkResults":[]}'],
    'no checkResults' => ['{"finishedAt":1700000000}'],
    'checkResults object' => ['{"finishedAt":1700000000,"checkResults":{"a":1}}'],
]);
