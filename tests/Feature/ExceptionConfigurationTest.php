<?php

declare(strict_types=1);

use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

it('keeps exception capture off by default', function () {
    $exceptions = (new ExcubiaConfigurationLoader)->load(null, temporaryProject([]))->exceptions;

    expect($exceptions->isEnabled)->toBeFalse()
        ->and($exceptions->maskPatterns)->toBe([])
        ->and($exceptions->ignoredClasses)->toBe([])
        ->and($exceptions->bufferMaxBytes)->toBe(1048576);
});

it('reads the exceptions key independently of runtime', function () {
    $configuration = (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => json_encode(['exceptions' => [
            'enabled' => true,
            'maskPatterns' => ['/ORD-[0-9]+/'],
            'ignore' => ['\\App\\Exceptions\\PaymentDeclinedException'],
            'bufferMaxBytes' => 8192,
        ]], JSON_THROW_ON_ERROR),
    ]));

    expect($configuration->runtime->isEnabled)->toBeFalse()
        ->and($configuration->exceptions->isEnabled)->toBeTrue()
        ->and($configuration->exceptions->maskPatterns)->toBe(['/ORD-[0-9]+/'])
        ->and($configuration->exceptions->ignoredClasses)->toBe(['App\\Exceptions\\PaymentDeclinedException'])
        ->and($configuration->exceptions->bufferMaxBytes)->toBe(8192);
});

it('rejects an invalid exceptions key', function (string $exceptions, string $message) {
    expect(fn () => (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => '{"exceptions": '.$exceptions.'}',
    ])))->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'not an object' => ['true', 'exceptions must be an object.'],
    'unknown key' => ['{"sampleRate": 1}', 'Unknown exceptions key [sampleRate].'],
    'enabled not boolean' => ['{"enabled": "yes"}', 'exceptions.enabled must be a boolean.'],
    'patterns not a list' => ['{"maskPatterns": "/x/"}', 'exceptions.maskPatterns must be a list of strings.'],
    'pattern not a string' => ['{"maskPatterns": [1]}', 'exceptions.maskPatterns[0] must be a non-empty string.'],
    'invalid pattern' => ['{"maskPatterns": ["/unclosed["]}', 'exceptions.maskPatterns[0] is not a valid regular expression.'],
    'ignore not a list' => ['{"ignore": {"a": "b"}}', 'exceptions.ignore must be a list of strings.'],
    'tiny buffer' => ['{"bufferMaxBytes": 10}', 'exceptions.bufferMaxBytes must be at least 4096.'],
]);

it('documents exceptions in the schema with the loader keys', function () {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/excubia.schema.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($schema['properties']['exceptions']['additionalProperties'])->toBeFalse()
        ->and(array_keys($schema['properties']['exceptions']['properties']))->toBe(['enabled', 'maskPatterns', 'ignore', 'bufferMaxBytes']);
});

it('documents the exceptions envelope in push-schema.json and the payload in exception-schema.json', function () {
    $docs = dirname(__DIR__, 2).'/docs/';
    $push = json_decode((string) file_get_contents($docs.'push-schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $exceptions = json_decode((string) file_get_contents($docs.'exception-schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $envelope = $push['$defs']['exceptionsEnvelope'];

    expect($envelope['properties']['protocolVersion']['const'])->toBe(1)
        ->and($envelope['properties']['type']['const'])->toBe('exceptions')
        ->and($envelope['required'])->toBe(['protocolVersion', 'type', 'batchId', 'sentAt', 'exceptions'])
        ->and($envelope['properties']['exceptions']['$ref'])->toEndWith('docs/exception-schema.json')
        ->and($exceptions['required'])->toBe(['groups'])
        ->and($exceptions['$defs']['group']['required'])->toBe(['class', 'fingerprint', 'message', 'frames', 'previous', 'context', 'environment', 'count', 'firstSeen', 'lastSeen']);
});
