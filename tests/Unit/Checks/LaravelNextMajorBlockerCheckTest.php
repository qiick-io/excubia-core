<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\LaravelNextMajorBlockerCheck;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\ComposerConstraintService;
use Qiick\Excubia\Services\PackagistApiService;
use Qiick\Excubia\Services\ResponseCacheService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;

function blockerCheck(array $documents, bool $offline = false): LaravelNextMajorBlockerCheck
{
    $directory = sys_get_temp_dir().'/excubia-blocker-'.bin2hex(random_bytes(4));

    return new LaravelNextMajorBlockerCheck(
        new PackagistApiService(new FakeHttpClient($documents), new ResponseCacheService($directory, 24, $offline, false)),
        new ComposerConstraintService,
    );
}

function release(string $name, string $version, array $require): array
{
    return [
        'version' => $version,
        'require' => $require,
    ];
}

it('skips when laravel/framework is not locked', function () {
    $result = blockerCheck([])->run(new ProjectSnapshotData('8.3.0.0', null, [], [], null), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Skipped);
});

it('fails a direct dependency whose latest release does not allow the next major', function () {
    $result = blockerCheck([
        'https://repo.packagist.org/p2/acme/widget.json' => [
            'packages' => ['acme/widget' => [
                release('acme/widget', 'v1.0.0', ['illuminate/support' => '^12.0']),
            ]],
        ],
        'https://repo.packagist.org/p2/symfony/console.json' => [
            'packages' => ['symfony/console' => [
                release('symfony/console', 'v7.2.0', ['php' => '^8.2']),
            ]],
        ],
    ])->run(new ProjectSnapshotData('8.3.0.0', 'v12.0.0', [
        new LockedPackageData('laravel/framework', 'v12.0.0', true, false),
        new LockedPackageData('acme/widget', 'v1.0.0', true, false),
        new LockedPackageData('symfony/console', 'v7.2.0', true, false),
        new LockedPackageData('old/lib', 'v1.0.0', false, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Fail)
        ->and($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->subject)->toBe('acme/widget')
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::Medium)
        ->and($result->findings[0]->fixHint)->toBe('No release supports Laravel 13 yet; watch / replace / fork.');
});

it('warns when only a pre-release allows the next major', function () {
    $result = blockerCheck([
        'https://repo.packagist.org/p2/acme/widget.json' => [
            'packages' => ['acme/widget' => [
                release('acme/widget', 'v1.0.0', ['illuminate/support' => '^10.0']),
                release('acme/widget', 'v1.1.0-beta.1', ['illuminate/support' => '^11.0']),
            ]],
        ],
    ])->run(new ProjectSnapshotData(null, 'v10.48.22', [
        new LockedPackageData('acme/widget', 'v1.0.0', true, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Warn)
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::Low)
        ->and($result->findings[0]->fixHint)->toContain('Laravel 11');
});

it('passes when the latest release allows the next major and skips an outage', function () {
    $documents = [
        'https://repo.packagist.org/p2/acme/widget.json' => [
            'packages' => ['acme/widget' => [
                release('acme/widget', 'v2.0.0', ['laravel/framework' => '^13.0']),
            ]],
        ],
    ];
    $snapshot = new ProjectSnapshotData(null, 'v12.0.0', [
        new LockedPackageData('acme/widget', 'v2.0.0', true, false),
    ], [], null);
    $passed = blockerCheck($documents)->run($snapshot, ExcubiaConfigurationData::defaults());
    $offline = blockerCheck($documents, offline: true)->run($snapshot, ExcubiaConfigurationData::defaults());

    expect($passed->status)->toBe(FindingStatusEnum::Pass)
        ->and($offline->status)->toBe(FindingStatusEnum::Skipped);
});

it('puts the package, locked version and target major in details', function () {
    $result = blockerCheck([
        'https://repo.packagist.org/p2/acme/widget.json' => [
            'packages' => ['acme/widget' => [
                release('acme/widget', 'v1.4.0', ['illuminate/support' => '^12.0']),
            ]],
        ],
    ])->run(new ProjectSnapshotData(null, 'v12.0.0', [
        new LockedPackageData('acme/widget', 'v1.3.0', true, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($result->findings[0]->details)->toBe(['package' => 'acme/widget', 'lockedVersion' => 'v1.3.0', 'targetMajor' => 13]);
});
