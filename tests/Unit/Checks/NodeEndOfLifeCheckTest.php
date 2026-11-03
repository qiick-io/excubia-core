<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\NodeEndOfLifeCheck;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\EndOfLifeApiService;
use Qiick\Excubia\Services\ResponseCacheService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;

function nodeEndOfLifeCheck(bool $offline = false): NodeEndOfLifeCheck
{
    $directory = sys_get_temp_dir().'/excubia-node-eol-'.bin2hex(random_bytes(4));
    $client = new FakeHttpClient([
        'https://endoflife.date/api/nodejs.json' => [
            ['cycle' => '22', 'eol' => '2028-04-30', 'support' => '2027-10-21', 'lts' => true],
            ['cycle' => '20', 'eol' => '2027-02-01', 'support' => '2026-04-01', 'lts' => true],
            ['cycle' => '18', 'eol' => '2025-04-30', 'support' => '2023-10-18', 'lts' => true],
        ],
    ]);

    return new NodeEndOfLifeCheck(
        new EndOfLifeApiService($client, new ResponseCacheService($directory, 24, $offline, false)),
        new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC')),
    );
}

function nodeSnapshot(?string $declaration): ProjectSnapshotData
{
    return new ProjectSnapshotData(null, null, [], [], $declaration);
}

it('skips when Node is not declared or the check is disabled', function () {
    $missing = nodeEndOfLifeCheck()->run(nodeSnapshot(null), ExcubiaConfigurationData::defaults());
    $disabled = new ExcubiaConfigurationData(null, true, 24, 180, 180, false, [], null, 24);
    $turnedOff = nodeEndOfLifeCheck()->run(nodeSnapshot('22'), $disabled);

    expect($missing->status)->toBe(FindingStatusEnum::Skipped)
        ->and($missing->findings[0]->message)->toContain('not declared')
        ->and($turnedOff->findings[0]->message)->toContain('disabled');
});

it('skips a declaration that has no major and an unknown cycle', function () {
    $alias = nodeEndOfLifeCheck()->run(nodeSnapshot('lts/jod'), ExcubiaConfigurationData::defaults());
    $unknown = nodeEndOfLifeCheck()->run(nodeSnapshot('>=16'), ExcubiaConfigurationData::defaults());

    expect($alias->findings[0]->message)->toContain('could not be read')
        ->and($unknown->findings[0]->message)->toContain('No end-of-life cycle');
});

it('fails an end-of-life release and points at the current LTS', function () {
    $result = nodeEndOfLifeCheck()->run(nodeSnapshot('.nvmrc wins: 18'), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Fail)
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::High)
        ->and($result->findings[0]->fixHint)->toBe('Move to Node LTS 22.');
});

it('warns when maintenance ends within 180 days', function () {
    $result = nodeEndOfLifeCheck()->run(nodeSnapshot('20.11.0'), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Warn)
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::Low);
});

it('passes a supported release and skips a data-source outage', function () {
    $supported = nodeEndOfLifeCheck()->run(nodeSnapshot('22'), ExcubiaConfigurationData::defaults());
    $offline = nodeEndOfLifeCheck(offline: true)->run(nodeSnapshot('22'), ExcubiaConfigurationData::defaults());

    expect($supported->status)->toBe(FindingStatusEnum::Pass)
        ->and($offline->status)->toBe(FindingStatusEnum::Skipped);
});

it('puts the matched cycle and its support end dates in details', function () {
    $failed = nodeEndOfLifeCheck()->run(nodeSnapshot('18'), ExcubiaConfigurationData::defaults());
    $warned = nodeEndOfLifeCheck()->run(nodeSnapshot('20.11.0'), ExcubiaConfigurationData::defaults());

    expect($failed->findings[0]->details)->toBe(['cycle' => '18', 'securitySupportEndsOn' => '2025-04-30', 'activeSupportEndsOn' => '2023-10-18'])
        ->and($warned->findings[0]->details)->toBe(['cycle' => '20', 'securitySupportEndsOn' => '2027-02-01', 'activeSupportEndsOn' => '2026-04-01']);
});
