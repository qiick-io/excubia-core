<?php

declare(strict_types=1);

use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

it('uses defaults when excubia.json is absent', function () {
    $root = temporaryProject([]);

    expect((new ExcubiaConfigurationLoader)->load(null, $root)->staleAfterMonths)
        ->toBe(ExcubiaConfigurationData::defaults()->staleAfterMonths);
});

it('loads the spec example and matches an advisory id', function () {
    $root = temporaryProject([
        'excubia.json' => <<<'JSON'
{
  "$schema": "https://raw.githubusercontent.com/qiick-io/excubia-core/main/excubia.schema.json",
  "failOn": "high",
  "includeDev": true,
  "checks": {
    "composer-stale": { "staleAfterMonths": 12 },
    "php-eol": { "warnDaysBeforeEol": 90 },
    "laravel-eol": { "warnDaysBeforeEol": 30 },
    "node-eol": { "enabled": false }
  },
  "ignore": [
    { "checkId": "composer-advisories", "subject": "vendor/package", "advisoryId": "GHSA-xxxx", "reason": "Not reachable", "until": "2027-01-31" }
  ],
  "cache": { "directory": null, "ttlHours": 12 }
}
JSON,
    ]);

    $configuration = (new ExcubiaConfigurationLoader)->load(null, $root);
    $today = new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC'));
    $matched = advisoryFinding('GHSA-xxxx');
    $other = advisoryFinding('GHSA-other');

    expect($configuration->staleAfterMonths)->toBe(12)
        ->and($configuration->phpWarnDaysBeforeEndOfLife)->toBe(90)
        ->and($configuration->laravelWarnDaysBeforeEndOfLife)->toBe(30)
        ->and($configuration->isNodeEndOfLifeCheckEnabled)->toBeFalse()
        ->and($configuration->cacheTtlHours)->toBe(12)
        ->and($configuration->isIgnored($matched, $today))->toBeTrue()
        ->and($configuration->isIgnored($other, $today))->toBeFalse();
});

it('rejects an unknown key', function () {
    $root = temporaryProject([
        'excubia.json' => '{"telemetry": true}',
    ]);

    expect(fn () => (new ExcubiaConfigurationLoader)->load(null, $root))
        ->toThrow(InvalidConfigurationException::class, 'Unknown excubia.json key [telemetry].');
});

it('rejects an ignore rule without a reason', function () {
    $root = temporaryProject([
        'excubia.json' => '{"ignore":[{"checkId":"php-eol","subject":"php"}]}',
    ]);

    expect(fn () => (new ExcubiaConfigurationLoader)->load(null, $root))
        ->toThrow(InvalidConfigurationException::class, 'reason');
});

it('reactivates an expired ignore and returns a warning', function () {
    $root = temporaryProject([
        'excubia.json' => '{"ignore":[{"checkId":"composer-advisories","subject":"vendor/package","advisoryId":"GHSA-xxxx","reason":"Not reachable","until":"2026-01-01"}]}',
    ]);
    $configuration = (new ExcubiaConfigurationLoader)->load(null, $root);
    $finding = advisoryFinding('GHSA-xxxx');
    $today = new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC'));

    expect($configuration->isIgnored($finding, $today))->toBeFalse()
        ->and($configuration->expiredIgnoreWarnings([$finding], $today)[0])->toContain('expired on 2026-01-01');
});

it('rejects a missing explicit config path', function () {
    expect(fn () => (new ExcubiaConfigurationLoader)->load('/tmp/does-not-exist-excubia.json', '/tmp'))
        ->toThrow(InvalidConfigurationException::class, 'was not found');
});

it('documents a closed schema with advisoryId on ignore rules', function () {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/excubia.schema.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['ignore']['items']['properties'])->toHaveKey('advisoryId')
        ->and($schema['properties']['ignore']['items']['required'])->toBe(['checkId', 'subject', 'reason']);
});

function advisoryFinding(string $advisoryId): FindingData
{
    return new FindingData(
        checkId: 'composer-advisories',
        severity: SeverityEnum::Critical,
        status: FindingStatusEnum::Fail,
        subject: 'vendor/package',
        message: 'Known vulnerability.',
        fixHint: 'Update vendor/package to ≥ fixed version ('.$advisoryId.').',
        evidenceUrl: null,
        advisoryId: $advisoryId,
    );
}
