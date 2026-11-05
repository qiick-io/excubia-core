<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\ComposerAbandonedCheck;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\PackagistApiService;
use Qiick\Excubia\Services\ResponseCacheService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;

function abandonedCheck(array $documents, bool $offline = false): ComposerAbandonedCheck
{
    $directory = sys_get_temp_dir().'/excubia-abandoned-'.bin2hex(random_bytes(4));

    return new ComposerAbandonedCheck(new PackagistApiService(
        new FakeHttpClient($documents),
        new ResponseCacheService($directory, 24, $offline, false),
    ));
}

function packageDocument(string $name, string|bool|null $abandoned): array
{
    return [
        'packages' => [
            $name => [[
                'version' => 'v1.0.0',
                'time' => '2024-01-01T00:00:00+00:00',
                'abandoned' => $abandoned,
            ]],
        ],
    ];
}

it('marks a direct abandoned package high and a transitive one medium', function () {
    $result = abandonedCheck([
        'https://repo.packagist.org/p2/swiftmailer/swiftmailer.json' => packageDocument('swiftmailer/swiftmailer', 'symfony/mailer'),
        'https://repo.packagist.org/p2/old/lib.json' => packageDocument('old/lib', true),
        'https://repo.packagist.org/p2/symfony/console.json' => packageDocument('symfony/console', null),
    ])->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('swiftmailer/swiftmailer', 'v6.3.0', true, false),
        new LockedPackageData('old/lib', 'v1.0.0', false, false),
        new LockedPackageData('symfony/console', 'v7.2.0', true, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Fail)
        ->and($result->findings[0]->status)->toBe(FindingStatusEnum::Fail)
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::High)
        ->and($result->findings[0]->fixHint)->toBe('Replace with symfony/mailer.')
        ->and($result->findings[1]->status)->toBe(FindingStatusEnum::Warn)
        ->and($result->findings[1]->severity)->toBe(SeverityEnum::Medium)
        ->and($result->findings[1]->fixHint)->toBe('no replacement suggested')
        ->and($result->findings)->toHaveCount(2);
});

it('warns when only a transitive dependency is abandoned', function () {
    $result = abandonedCheck([
        'https://repo.packagist.org/p2/old/lib.json' => packageDocument('old/lib', true),
    ])->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('old/lib', 'v1.0.0', false, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Warn)
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::Medium);
});

it('passes when nothing is abandoned and skips an outage', function () {
    $passed = abandonedCheck([
        'https://repo.packagist.org/p2/symfony/console.json' => packageDocument('symfony/console', false),
    ])->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('symfony/console', 'v7.2.0', true, false),
    ], [], null), ExcubiaConfigurationData::defaults());
    $offline = abandonedCheck([], offline: true)->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('symfony/console', 'v7.2.0', true, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($passed->status)->toBe(FindingStatusEnum::Pass)
        ->and($offline->status)->toBe(FindingStatusEnum::Skipped);
});

it('puts the package, locked version and known replacement in details', function () {
    $result = abandonedCheck([
        'https://repo.packagist.org/p2/swiftmailer/swiftmailer.json' => packageDocument('swiftmailer/swiftmailer', 'symfony/mailer'),
        'https://repo.packagist.org/p2/old/lib.json' => packageDocument('old/lib', true),
    ])->run(new ProjectSnapshotData(null, null, [
        new LockedPackageData('swiftmailer/swiftmailer', 'v6.3.0', true, false),
        new LockedPackageData('old/lib', 'v1.0.0', false, false),
    ], [], null), ExcubiaConfigurationData::defaults());

    expect($result->findings[0]->details)->toBe(['package' => 'swiftmailer/swiftmailer', 'lockedVersion' => 'v6.3.0', 'replacement' => 'symfony/mailer'])
        ->and($result->findings[1]->details)->toBe(['package' => 'old/lib', 'lockedVersion' => 'v1.0.0', 'replacement' => null]);
});
