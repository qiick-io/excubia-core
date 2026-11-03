<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\LaravelEndOfLifeCheck;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\EndOfLifeApiService;
use Qiick\Excubia\Services\ResponseCacheService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;

function laravelEndOfLifeCheck(bool $offline = false): LaravelEndOfLifeCheck
{
    $directory = sys_get_temp_dir().'/excubia-laravel-eol-'.bin2hex(random_bytes(4));
    $client = new FakeHttpClient([
        'https://endoflife.date/api/laravel.json' => [
            ['cycle' => '13', 'eol' => '2028-03-17', 'support' => '2027-09-17', 'lts' => false],
            ['cycle' => '10', 'eol' => '2025-02-04', 'support' => '2024-08-06', 'lts' => false],
            ['cycle' => '12', 'eol' => '2026-12-01', 'support' => '2026-02-01', 'lts' => false],
        ],
    ]);

    return new LaravelEndOfLifeCheck(
        new EndOfLifeApiService($client, new ResponseCacheService($directory, 24, $offline, false)),
        new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC')),
    );
}

function laravelSnapshot(?string $version): ProjectSnapshotData
{
    return new ProjectSnapshotData('8.3.0.0', $version, [], [], null);
}

it('skips when laravel/framework is not locked', function () {
    $result = laravelEndOfLifeCheck()->run(laravelSnapshot(null), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Skipped);
});

it('fails when security fixes have ended', function () {
    $result = laravelEndOfLifeCheck()->run(laravelSnapshot('v10.48.22'), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Fail)
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::Critical)
        ->and($result->findings[0]->fixHint)->toBe('Upgrade to Laravel 11; security fixes for your version end 2025-02-04.');
});

it('warns when bug fixes have ended and names the next major', function () {
    $result = laravelEndOfLifeCheck()->run(laravelSnapshot('v12.0.0'), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Warn)
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::Medium)
        ->and($result->findings[0]->fixHint)->toContain('Laravel 13');
});

it('skips when the locked version has no matching cycle', function () {
    $missing = laravelEndOfLifeCheck()->run(laravelSnapshot('dev-main'), ExcubiaConfigurationData::defaults());
    $unknown = laravelEndOfLifeCheck()->run(laravelSnapshot('v9.0.0'), ExcubiaConfigurationData::defaults());

    expect($missing->status)->toBe(FindingStatusEnum::Skipped)
        ->and($unknown->findings[0]->message)->toContain('No end-of-life cycle');
});

it('passes for a supported release and skips when the source is down', function () {
    $supported = laravelEndOfLifeCheck()->run(laravelSnapshot('v13.1.0'), ExcubiaConfigurationData::defaults());
    $offline = laravelEndOfLifeCheck(offline: true)->run(laravelSnapshot('v13.1.0'), ExcubiaConfigurationData::defaults());

    expect($supported->status)->toBe(FindingStatusEnum::Pass)
        ->and($offline->status)->toBe(FindingStatusEnum::Skipped);
});

it('puts the matched cycle and its support end dates in details', function () {
    $failed = laravelEndOfLifeCheck()->run(laravelSnapshot('v10.48.22'), ExcubiaConfigurationData::defaults());
    $warned = laravelEndOfLifeCheck()->run(laravelSnapshot('v12.0.0'), ExcubiaConfigurationData::defaults());

    expect($failed->findings[0]->details)->toBe(['cycle' => '10', 'securitySupportEndsOn' => '2025-02-04', 'activeSupportEndsOn' => '2024-08-06'])
        ->and($warned->findings[0]->details)->toBe(['cycle' => '12', 'securitySupportEndsOn' => '2026-12-01', 'activeSupportEndsOn' => '2026-02-01']);
});
