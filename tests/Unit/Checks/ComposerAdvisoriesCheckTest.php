<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\ComposerAdvisoriesCheck;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\ComposerConstraintService;
use Qiick\Excubia\Services\PackagistApiService;
use Qiick\Excubia\Services\ResponseCacheService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;

function composerAdvisoriesCheck(array $document, bool $offline = false): ComposerAdvisoriesCheck
{
    $directory = sys_get_temp_dir().'/excubia-adv-'.bin2hex(random_bytes(4));
    $client = new FakeHttpClient([
        'https://packagist.org/api/security-advisories/' => $document,
    ]);

    return new ComposerAdvisoriesCheck(
        new PackagistApiService($client, new ResponseCacheService($directory, 24, $offline, false)),
        new ComposerConstraintService,
    );
}

it('fails a locked version the advisory affects and keeps the advisory id', function () {
    $result = composerAdvisoriesCheck([
        'advisories' => [
            'guzzlehttp/guzzle' => [[
                'advisoryId' => 'PKSA-example',
                'packageName' => 'guzzlehttp/guzzle',
                'affectedVersions' => '<7.15.2',
                'severity' => 'high',
                'link' => 'https://github.com/advisories/GHSA-example',
            ]],
        ],
    ])->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('guzzlehttp/guzzle', '7.9.0', true, false),
        new LockedPackageData('symfony/console', '7.2.0', true, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Fail)
        ->and($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::High)
        ->and($result->findings[0]->advisoryId)->toBe('PKSA-example')
        ->and($result->findings[0]->fixHint)->toBe('Update guzzlehttp/guzzle to ≥ fixed version (PKSA-example).');
});

it('passes when the locked version is outside the advisory', function () {
    $result = composerAdvisoriesCheck([
        'advisories' => [
            'guzzlehttp/guzzle' => [[
                'advisoryId' => 'PKSA-example',
                'packageName' => 'guzzlehttp/guzzle',
                'affectedVersions' => '<7.0.0',
                'severity' => 'nope',
            ]],
        ],
    ])->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('guzzlehttp/guzzle', '7.9.0', true, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Pass);
});

it('skips when Packagist does not respond', function () {
    $result = composerAdvisoriesCheck([], offline: true)->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('guzzlehttp/guzzle', '7.9.0', true, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Skipped);
});

it('puts the package, locked version, affected range and fixed versions in details', function () {
    $result = composerAdvisoriesCheck([
        'advisories' => [
            'laravel/framework' => [[
                'advisoryId' => 'PKSA-ranges',
                'packageName' => 'laravel/framework',
                'affectedVersions' => '>=8.0.0,<8.0.1|>=10.0.0 <10.48.23|>=11.0.0,<=11.2.0|<7.15.2',
                'severity' => 'high',
            ]],
        ],
    ])->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('laravel/framework', 'v10.48.22', true, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($result->findings[0]->details)->toBe([
        'package' => 'laravel/framework',
        'lockedVersion' => 'v10.48.22',
        'affectedVersions' => '>=8.0.0,<8.0.1|>=10.0.0 <10.48.23|>=11.0.0,<=11.2.0|<7.15.2',
        'fixedVersions' => ['8.0.1', '10.48.23', '7.15.2'],
    ]);
});
