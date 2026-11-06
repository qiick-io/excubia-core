<?php

declare(strict_types=1);

use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

it('keeps runtime monitoring off by default with every type on and full sampling', function () {
    $runtime = (new ExcubiaConfigurationLoader)->load(null, temporaryProject([]))->runtime;

    expect($runtime->isEnabled)->toBeFalse()
        ->and($runtime->bufferMaxBytes)->toBe(1048576)
        ->and($runtime->slowQueryThresholdMs)->toBe(1000);

    foreach (RuntimeTypeEnum::cases() as $type) {
        expect($runtime->isTypeEnabled($type))->toBeFalse()
            ->and($runtime->sampleRate($type))->toBe(1.0);
    }
});

it('reads the runtime key with per-type switches, sample rates and the slow query threshold', function () {
    $runtime = (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => json_encode(['runtime' => [
            'enabled' => true,
            'bufferMaxBytes' => 65536,
            'requests' => ['sampleRate' => 0.25],
            'slowQueries' => ['thresholdMs' => 250],
            'jobs' => ['enabled' => false],
            'scheduler' => ['sampleRate' => 1],
            'outgoingHttp' => ['enabled' => true, 'sampleRate' => 0],
        ]], JSON_THROW_ON_ERROR),
    ]))->runtime;

    expect($runtime->isEnabled)->toBeTrue()
        ->and($runtime->bufferMaxBytes)->toBe(65536)
        ->and($runtime->slowQueryThresholdMs)->toBe(250)
        ->and($runtime->isTypeEnabled(RuntimeTypeEnum::Request))->toBeTrue()
        ->and($runtime->sampleRate(RuntimeTypeEnum::Request))->toBe(0.25)
        ->and($runtime->isTypeEnabled(RuntimeTypeEnum::Job))->toBeFalse()
        ->and($runtime->isTypeEnabled(RuntimeTypeEnum::SlowQuery))->toBeTrue()
        ->and($runtime->sampleRate(RuntimeTypeEnum::ScheduledTask))->toBe(1.0)
        ->and($runtime->sampleRate(RuntimeTypeEnum::OutgoingHttp))->toBe(0.0);
});

it('keeps the types added in 1.0.0-beta.2 off by default and reads their switches and sample rates', function () {
    $load = static fn (array $runtime) => (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => json_encode(['runtime' => $runtime], JSON_THROW_ON_ERROR),
    ]))->runtime;
    $added = [RuntimeTypeEnum::RequestMemory, RuntimeTypeEnum::JobMemory, RuntimeTypeEnum::QueueWait, RuntimeTypeEnum::Cache, RuntimeTypeEnum::Mail, RuntimeTypeEnum::Notification, RuntimeTypeEnum::LogLevel];
    $defaults = $load(['enabled' => true]);

    foreach (RuntimeTypeEnum::cases() as $type) {
        expect($defaults->isTypeEnabled($type))->toBe(! in_array($type, $added, true));
    }

    $switched = $load(['enabled' => true, 'cache' => ['enabled' => true, 'sampleRate' => 0.1], 'logLevels' => ['enabled' => true], 'notifications' => ['enabled' => false]]);

    expect($switched->isTypeEnabled(RuntimeTypeEnum::Cache))->toBeTrue()
        ->and($switched->sampleRate(RuntimeTypeEnum::Cache))->toBe(0.1)
        ->and($switched->isTypeEnabled(RuntimeTypeEnum::LogLevel))->toBeTrue()
        ->and($switched->isTypeEnabled(RuntimeTypeEnum::Notification))->toBeFalse()
        ->and(fn () => $load(['cache' => ['thresholdMs' => 5]]))->toThrow(InvalidConfigurationException::class, 'Unknown runtime.cache key [thresholdMs].')
        ->and(fn () => $load(['logLevels' => ['sampleRate' => 2]]))->toThrow(InvalidConfigurationException::class, 'runtime.logLevels.sampleRate must be between 0 and 1.');
});

it('rejects an invalid runtime key', function (string $runtime, string $message) {
    expect(fn () => (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => '{"runtime": '.$runtime.'}',
    ])))->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'not an object' => ['true', 'runtime must be an object.'],
    'unknown key' => ['{"url": "https://x"}', 'Unknown runtime key [url].'],
    'type not an object' => ['{"jobs": true}', 'runtime.jobs must be an object.'],
    'unknown type key' => ['{"jobs": {"thresholdMs": 5}}', 'Unknown runtime.jobs key [thresholdMs].'],
    'enabled not boolean' => ['{"enabled": 1}', 'runtime.enabled must be a boolean.'],
    'sample rate above 1' => ['{"requests": {"sampleRate": 1.5}}', 'runtime.requests.sampleRate must be between 0 and 1.'],
    'sample rate not a number' => ['{"requests": {"sampleRate": "all"}}', 'runtime.requests.sampleRate must be a number between 0 and 1.'],
    'negative threshold' => ['{"slowQueries": {"thresholdMs": -1}}', 'runtime.slowQueries.thresholdMs must be zero or greater.'],
    'tiny buffer' => ['{"bufferMaxBytes": 10}', 'runtime.bufferMaxBytes must be at least 4096.'],
]);

it('documents runtime in the schema with the loader keys', function () {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/excubia.schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $typeKeys = array_map(fn (RuntimeTypeEnum $type): string => $type->configurationKey(), RuntimeTypeEnum::cases());

    expect($schema['properties']['runtime']['additionalProperties'])->toBeFalse()
        ->and(array_keys($schema['properties']['runtime']['properties']))->toBe(['enabled', 'bufferMaxBytes', ...$typeKeys])
        ->and(array_keys($schema['properties']['runtime']['properties']['slowQueries']['properties']))->toBe(['enabled', 'sampleRate', 'thresholdMs'])
        ->and(array_keys($schema['$defs']['runtimeType']['properties']))->toBe(['enabled', 'sampleRate']);
});

it('documents the runtime envelope in push-schema.json and the payload in runtime-schema.json', function () {
    $docs = dirname(__DIR__, 2).'/docs/';
    $push = json_decode((string) file_get_contents($docs.'push-schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $runtime = json_decode((string) file_get_contents($docs.'runtime-schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $envelope = $push['$defs']['runtimeEnvelope'];
    $documentedTypes = array_map(fn (array $item): string => substr($item['$ref'], strlen('#/$defs/')), $runtime['properties']['aggregates']['items']['oneOf']);

    expect($envelope['properties']['protocolVersion']['const'])->toBe(1)
        ->and($envelope['properties']['type']['const'])->toBe('runtime')
        ->and($envelope['required'])->toBe(['protocolVersion', 'type', 'batchId', 'sentAt', 'runtime'])
        ->and($envelope['properties']['runtime']['$ref'])->toEndWith('docs/runtime-schema.json')
        ->and($documentedTypes)->toBe(array_map(fn (RuntimeTypeEnum $type): string => $type->value, RuntimeTypeEnum::cases()));
});
