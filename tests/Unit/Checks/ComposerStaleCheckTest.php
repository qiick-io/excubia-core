<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\ComposerStaleCheck;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\GitHubRepositoryApiService;
use Qiick\Excubia\Services\PackagistApiService;
use Qiick\Excubia\Services\ResponseCacheService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;

function staleCheck(array $documents, bool $offline = false): ComposerStaleCheck
{
    $directory = sys_get_temp_dir().'/excubia-stale-'.bin2hex(random_bytes(4));
    $cache = new ResponseCacheService($directory, 24, $offline, false);
    $client = new FakeHttpClient($documents);

    return new ComposerStaleCheck(
        new PackagistApiService($client, $cache),
        new GitHubRepositoryApiService($client, $cache),
        new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC')),
    );
}

function metadata(string $name, string $version, string $time, ?string $source): array
{
    return [
        'packages' => [
            $name => [[
                'version' => $version,
                'time' => $time,
                'source' => $source === null ? null : ['url' => $source],
            ]],
        ],
    ];
}

it('warns when jenssegers/agent has no recent release', function () {
    $result = staleCheck([
        'https://repo.packagist.org/p2/jenssegers/agent.json' => metadata('jenssegers/agent', 'v2.6.4', '2020-06-13T08:05:20+00:00', 'https://github.com/jenssegers/agent.git'),
        'https://api.github.com/repos/jenssegers/agent' => ['full_name' => 'jenssegers/agent', 'archived' => false, 'pushed_at' => '2024-08-05T12:34:44Z'],
    ])->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('jenssegers/agent', 'v2.6.4', true, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Warn)
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::Low)
        ->and($result->findings[0]->fixHint)->toBe('Consider a maintained alternative.');
});

it('fails when the GitHub repository is archived', function () {
    $result = staleCheck([
        'https://repo.packagist.org/p2/old/pkg.json' => metadata('old/pkg', 'v1.2.0', '2026-09-01T00:00:00+00:00', 'https://github.com/old/pkg.git'),
        'https://api.github.com/repos/old/pkg' => ['full_name' => 'old/pkg', 'archived' => true, 'pushed_at' => '2026-09-01T00:00:00Z'],
    ])->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('old/pkg', 'v1.2.0', true, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Fail)
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::High);
});

it('ignores a dev release, a non-GitHub source, and a bad timestamp', function () {
    $result = staleCheck([
        'https://repo.packagist.org/p2/example/pkg.json' => [
            'packages' => [
                'example/pkg' => [
                    ['version' => 'dev-main', 'time' => '2020-01-01T00:00:00+00:00', 'source' => ['url' => 'https://github.com/example/pkg.git']],
                    ['version' => 'v1.0.0', 'time' => '2026-01-01T00:00:00+00:00', 'source' => null],
                    ['version' => 'v1.1.0', 'time' => '2026-09-01T00:00:00+00:00', 'source' => ['url' => 'https://gitlab.com/example/pkg.git']],
                ],
            ],
        ],
        'https://repo.packagist.org/p2/other/pkg.json' => metadata('other/pkg', 'v1.0.0', 'not-a-date', null),
    ])->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('example/pkg', 'v1.1.0', true, false),
        new LockedPackageData('other/pkg', 'v1.0.0', false, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Pass);
});

it('skips when GitHub does not respond', function () {
    $result = staleCheck([
        'https://repo.packagist.org/p2/example/pkg.json' => metadata('example/pkg', 'v1.0.0', '2026-09-01T00:00:00+00:00', 'https://github.com/example/pkg.git'),
    ])->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('example/pkg', 'v1.0.0', true, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($result->findings[0]->message)->toContain('GitHub did not respond');
});

it('passes a recent release and skips when a data source is down', function () {
    $passed = staleCheck([
        'https://repo.packagist.org/p2/symfony/console.json' => metadata('symfony/console', 'v7.4.0', '2026-09-01T00:00:00+00:00', 'https://github.com/symfony/console.git'),
        'https://api.github.com/repos/symfony/console' => ['full_name' => 'symfony/console', 'archived' => false, 'pushed_at' => '2026-10-01T00:00:00Z'],
    ])->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('symfony/console', 'v7.4.0', true, false),
    ], [], null), ExcubiaConfigurationData::defaults());
    $offline = staleCheck([], offline: true)->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('symfony/console', 'v7.4.0', true, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($passed->status)->toBe(FindingStatusEnum::Pass)
        ->and($offline->status)->toBe(FindingStatusEnum::Skipped);
});

it('puts the package and locked version in details', function () {
    $result = staleCheck([
        'https://repo.packagist.org/p2/jenssegers/agent.json' => metadata('jenssegers/agent', 'v2.6.4', '2020-06-13T08:05:20+00:00', 'https://github.com/jenssegers/agent.git'),
        'https://api.github.com/repos/jenssegers/agent' => ['full_name' => 'jenssegers/agent', 'archived' => false, 'pushed_at' => '2024-08-05T12:34:44Z'],
    ])->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('jenssegers/agent', 'v2.6.3', true, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($result->findings[0]->details)->toBe(['package' => 'jenssegers/agent', 'lockedVersion' => 'v2.6.3']);
});
