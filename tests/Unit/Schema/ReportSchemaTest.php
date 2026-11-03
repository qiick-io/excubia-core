<?php

declare(strict_types=1);

use Qiick\Excubia\Enums\SkipReasonEnum;

it('documents schema version 1 and the finding fields from the spec', function () {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/docs/report-schema.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($schema['properties']['schemaVersion']['const'])->toBe(1)
        ->and($schema['$defs']['finding']['required'])->toBe([
            'checkId',
            'severity',
            'status',
            'subject',
            'message',
            'fixHint',
            'evidenceUrl',
        ])
        ->and($schema['$defs']['finding']['properties']['advisoryId']['type'])->toBe(['string', 'null']);
});

it('documents the optional skipReason with the enum values', function () {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/docs/report-schema.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($schema['$defs']['finding']['required'])->not->toContain('skipReason')
        ->and($schema['$defs']['finding']['properties']['skipReason']['enum'])
        ->toBe(array_map(fn (SkipReasonEnum $reason): string => $reason->value, SkipReasonEnum::cases()));
});

it('documents push envelope version 1', function () {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/docs/push-schema.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($schema['properties']['protocolVersion']['const'])->toBe(1)
        ->and($schema['required'])->toBe(['protocolVersion', 'batchId', 'sentAt', 'report'])
        ->and($schema['properties']['report']['$ref'])->toEndWith('docs/report-schema.json');
});
