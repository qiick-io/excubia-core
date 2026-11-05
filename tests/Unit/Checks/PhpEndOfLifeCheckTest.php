<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\PhpEndOfLifeCheck;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\EndOfLifeApiService;
use Qiick\Excubia\Services\ResponseCacheService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;

function phpEndOfLifeCheck(array $cycles, bool $offline = false): PhpEndOfLifeCheck
{
    $directory = sys_get_temp_dir().'/excubia-php-eol-'.bin2hex(random_bytes(4));
    $client = new FakeHttpClient([
        'https://endoflife.date/api/php.json' => $cycles,
    ]);

    return new PhpEndOfLifeCheck(
        new EndOfLifeApiService($client, new ResponseCacheService($directory, 24, $offline, false)),
        new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC')),
    );
}

function phpCycles(): array
{
    return [
        ['cycle' => '8.4', 'releaseDate' => '2024-11-21', 'eol' => '2028-12-31', 'support' => '2026-12-31', 'lts' => false],
        ['cycle' => '8.2', 'releaseDate' => '2022-12-08', 'eol' => '2026-12-31', 'support' => '2024-12-31', 'lts' => false],
        ['cycle' => '8.1', 'releaseDate' => '2021-11-25', 'eol' => '2025-12-31', 'support' => '2023-11-25', 'lts' => false],
    ];
}

function phpSnapshot(?string $phpVersion): ProjectSnapshotData
{
    return new ProjectSnapshotData($phpVersion, null, [], [], null);
}

it('fails when security support has ended', function () {
    $result = phpEndOfLifeCheck(phpCycles())->run(phpSnapshot('8.1.32.0'), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Fail)
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::Critical)
        ->and($result->findings[0]->fixHint)->toBe('Upgrade PHP to 8.4 (supported until 2028-12-31).');
});

it('warns when active support has ended and security support is close', function () {
    $result = phpEndOfLifeCheck(phpCycles())->run(phpSnapshot('8.2.0.0'), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Warn)
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::Medium)
        ->and($result->findings[0]->message)->toContain('2026-12-31');
});

it('passes when the release is supported', function () {
    $result = phpEndOfLifeCheck(phpCycles())->run(phpSnapshot('8.4.1.0'), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Pass)
        ->and($result->findings)->toBe([]);
});

it('skips when PHP is not declared or the data source is down', function () {
    $missing = phpEndOfLifeCheck(phpCycles())->run(phpSnapshot(null), ExcubiaConfigurationData::defaults());
    $offline = phpEndOfLifeCheck(phpCycles(), offline: true)->run(phpSnapshot('8.4.1.0'), ExcubiaConfigurationData::defaults());

    expect($missing->status)->toBe(FindingStatusEnum::Skipped)
        ->and($offline->status)->toBe(FindingStatusEnum::Skipped);
});

it('skips when no cycle matches', function () {
    $result = phpEndOfLifeCheck(phpCycles())->run(phpSnapshot('7.4.0.0'), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Skipped)
        ->and($result->findings[0]->message)->toContain('No end-of-life cycle');
});

it('puts the matched cycle and its support end dates in details', function () {
    $failed = phpEndOfLifeCheck(phpCycles())->run(phpSnapshot('8.1.32.0'), ExcubiaConfigurationData::defaults());
    $warned = phpEndOfLifeCheck(phpCycles())->run(phpSnapshot('8.2.0.0'), ExcubiaConfigurationData::defaults());

    expect($failed->findings[0]->details)->toBe(['cycle' => '8.1', 'securitySupportEndsOn' => '2025-12-31', 'activeSupportEndsOn' => '2023-11-25'])
        ->and($warned->findings[0]->details)->toBe(['cycle' => '8.2', 'securitySupportEndsOn' => '2026-12-31', 'activeSupportEndsOn' => '2024-12-31']);
});
