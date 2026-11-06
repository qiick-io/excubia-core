<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\ComposerOutdatedCheck;
use Qiick\Excubia\Checks\ComposerStaleCheck;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Enums\SkipReasonEnum;
use Qiick\Excubia\Services\ComposerConstraintService;
use Qiick\Excubia\Services\GitHubRepositoryApiService;
use Qiick\Excubia\Services\HealthCheckRunnerService;
use Qiick\Excubia\Services\PackagistApiService;
use Qiick\Excubia\Services\ResponseCacheService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;

const MONOLOG_P2_URL = 'https://repo.packagist.org/p2/monolog/monolog.json';

/**
 * The recorded p2 answer for monolog/monolog: 3.8.1, 3.8.0, 3.7.0,
 * 3.0.0-RC1, 2.10.0, 2.9.3 and 2.9.1.
 *
 * @return array<string, mixed>
 */
function monologMetadata(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Responses/packagist-p2-monolog.json'), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param  list<string>  $versions
 * @return array<string, mixed>
 */
function versionsMetadata(string $name, array $versions): array
{
    return ['packages' => [$name => array_map(static fn (string $version): array => ['version' => $version], $versions)]];
}

/**
 * @param  array<string, array<int|string, mixed>>  $responses
 * @param  list<LockedPackageData>  $packages
 */
function outdatedRun(array $responses, array $packages, ?FakeHttpClient &$client = null, bool $isOffline = false): CheckResultData
{
    $client = new FakeHttpClient($responses);
    $cache = new ResponseCacheService(null, 24, $isOffline, true);
    $check = new ComposerOutdatedCheck(new PackagistApiService($client, $cache), new ComposerConstraintService);

    return $check->run(new ProjectSnapshotData(null, null, $packages, [], null), ExcubiaConfigurationData::defaults());
}

it('reports a package behind on a major with the newest release of its major and the newest major', function () {
    $result = outdatedRun([MONOLOG_P2_URL => monologMetadata()], [new LockedPackageData('monolog/monolog', '2.9.1', true, false)]);
    $finding = $result->findings[0];

    expect($result->status)->toBe(FindingStatusEnum::Warn)
        ->and($result->findings)->toHaveCount(1)
        ->and($finding->severity)->toBe(SeverityEnum::Low)
        ->and($finding->subject)->toBe('monolog/monolog')
        ->and($finding->message)->toBe('A new major is available: 3.8.1.')
        ->and($finding->fixHint)->toBe('Update to 2.10.0, then plan the upgrade to 3.8.1.')
        ->and($finding->evidenceUrl)->toBe('https://packagist.org/packages/monolog/monolog')
        ->and($finding->details)->toBe([
            'package' => 'monolog/monolog',
            'lockedVersion' => '2.9.1',
            'latestPatch' => '2.9.3',
            'latestMinor' => '2.10.0',
            'latestMajor' => '3.8.1',
            'isMajorBehind' => true,
        ]);
});

it('reports a package behind on a minor at low and one behind on a patch at info', function () {
    $minor = outdatedRun([MONOLOG_P2_URL => monologMetadata()], [new LockedPackageData('monolog/monolog', 'v3.7.0', true, false)])->findings[0];
    $patch = outdatedRun([MONOLOG_P2_URL => monologMetadata()], [new LockedPackageData('monolog/monolog', '3.8.0', true, true)])->findings[0];

    expect($minor->severity)->toBe(SeverityEnum::Low)
        ->and($minor->status)->toBe(FindingStatusEnum::Warn)
        ->and($minor->message)->toBe('Behind on a minor: 3.8.1 is available.')
        ->and($minor->fixHint)->toBe('Update to 3.8.1.')
        ->and($minor->details)->toBe(['package' => 'monolog/monolog', 'lockedVersion' => 'v3.7.0', 'latestPatch' => null, 'latestMinor' => '3.8.1', 'latestMajor' => null, 'isMajorBehind' => false])
        ->and($patch->severity)->toBe(SeverityEnum::Info)
        ->and($patch->status)->toBe(FindingStatusEnum::Warn)
        ->and($patch->message)->toBe('Behind on a patch: 3.8.1 is available.')
        ->and($patch->details)->toBe(['package' => 'monolog/monolog', 'lockedVersion' => '3.8.0', 'latestPatch' => '3.8.1', 'latestMinor' => '3.8.1', 'latestMajor' => null, 'isMajorBehind' => false]);
});

it('passes the newest release and leaves transitive packages and dev branches alone', function () {
    $client = null;
    $result = outdatedRun([MONOLOG_P2_URL => monologMetadata()], [
        new LockedPackageData('monolog/monolog', '3.8.1', true, false),
        new LockedPackageData('psr/log', '1.0.0', false, false),
        new LockedPackageData('acme/branch', 'dev-main', true, false),
        new LockedPackageData('acme/alias', '2.x-dev', true, false),
        new LockedPackageData('acme/garbage', 'not a version', true, false),
    ], $client);

    expect($result->status)->toBe(FindingStatusEnum::Pass)
        ->and($result->findings)->toBe([])
        ->and($client->requests)->toBe(['GET '.MONOLOG_P2_URL.' []']);
});

it('ignores pre-releases unless the locked version is a pre-release', function () {
    $metadata = versionsMetadata('vendor/package', ['dev-main', '4.0.0-alpha1', '4.0.0-beta2', '4.1.0-RC1', '3.2.1', 'v3.2.0', 'not-a-version']);
    $stable = outdatedRun(['https://repo.packagist.org/p2/vendor/package.json' => $metadata], [new LockedPackageData('vendor/package', '3.2.1', true, false)]);
    $preRelease = outdatedRun(['https://repo.packagist.org/p2/vendor/package.json' => $metadata], [new LockedPackageData('vendor/package', '4.0.0-alpha1', true, false)]);

    expect($stable->status)->toBe(FindingStatusEnum::Pass)
        ->and($preRelease->findings[0]->details)->toBe([
            'package' => 'vendor/package',
            'lockedVersion' => '4.0.0-alpha1',
            'latestPatch' => '4.0.0-beta2',
            'latestMinor' => '4.1.0-RC1',
            'latestMajor' => null,
            'isMajorBehind' => false,
        ])
        ->and($preRelease->findings[0]->severity)->toBe(SeverityEnum::Low);
});

it('advises the next major alone when the locked major has nothing newer', function () {
    $finding = outdatedRun(
        ['https://repo.packagist.org/p2/vendor/package.json' => versionsMetadata('vendor/package', ['2.0.0', '1.4.0'])],
        [new LockedPackageData('vendor/package', '1.4.0', true, false)],
    )->findings[0];

    expect($finding->fixHint)->toBe('Plan the upgrade to 2.0.0.')
        ->and($finding->details['latestMinor'])->toBeNull()
        ->and($finding->details['isMajorBehind'])->toBeTrue();
});

it('skips private direct dependencies without sending their names', function () {
    $client = null;
    $result = outdatedRun([MONOLOG_P2_URL => monologMetadata()], [
        new LockedPackageData('monolog/monolog', '3.8.1', true, false),
        new LockedPackageData('acme/private', '1.0.0', true, false, isPrivate: true),
        new LockedPackageData('acme/transitive', '1.0.0', false, false, isPrivate: true),
    ], $client);

    expect($result->status)->toBe(FindingStatusEnum::Skipped)
        ->and($result->findings[0]->message)->toBe('1 private package not sent to Packagist: acme/private.')
        ->and($result->findings[0]->skipReason)->toBe(SkipReasonEnum::NotApplicable)
        ->and(implode("\n", $client->requests))->not->toContain('acme');
});

it('skips when Packagist does not respond', function () {
    $result = outdatedRun([], [new LockedPackageData('monolog/monolog', '3.8.0', true, false)], isOffline: true);

    expect($result->status)->toBe(FindingStatusEnum::Skipped)
        ->and($result->findings[0]->skipReason)->toBe(SkipReasonEnum::DataSourceFailure)
        ->and($result->findings[0]->message)->toBe('Packagist metadata did not respond.');
});

it('sends no extra request after the stale check fetched the metadata', function () {
    $client = new FakeHttpClient([
        MONOLOG_P2_URL => monologMetadata(),
        'https://api.github.com/repos/Seldaek/monolog' => ['full_name' => 'Seldaek/monolog', 'archived' => false, 'pushed_at' => '2026-10-01T00:00:00Z'],
    ]);
    $packagist = new PackagistApiService($client, new ResponseCacheService(null, 24, false, true));
    $snapshot = new ProjectSnapshotData(null, null, [new LockedPackageData('monolog/monolog', '3.8.0', true, false)], [], null);
    $today = new DateTimeImmutable('2026-11-06', new DateTimeZone('UTC'));

    (new ComposerStaleCheck($packagist, new GitHubRepositoryApiService($client, new ResponseCacheService(null, 24, false, true)), $today))->run($snapshot, ExcubiaConfigurationData::defaults());
    $requestsAfterStale = $client->requests;
    $result = (new ComposerOutdatedCheck($packagist, new ComposerConstraintService))->run($snapshot, ExcubiaConfigurationData::defaults());

    expect($result->status)->toBe(FindingStatusEnum::Warn)
        ->and($requestsAfterStale)->toContain('GET '.MONOLOG_P2_URL.' []')
        ->and($client->requests)->toBe($requestsAfterStale);
});

it('fetches each p2 document once per run across the Composer checks, even with --no-cache', function () {
    $root = temporaryProject([
        'composer.json' => json_encode(['require' => ['monolog/monolog' => '^2.9']], JSON_THROW_ON_ERROR),
        'composer.lock' => json_encode(['packages' => [
            ['name' => 'monolog/monolog', 'version' => '2.9.1', 'notification-url' => 'https://packagist.org/downloads/'],
        ], 'packages-dev' => []], JSON_THROW_ON_ERROR),
    ]);
    $client = new FakeHttpClient([
        MONOLOG_P2_URL => monologMetadata(),
        'https://api.github.com/repos/Seldaek/monolog' => ['full_name' => 'Seldaek/monolog', 'archived' => false, 'pushed_at' => '2026-10-01T00:00:00Z'],
    ]);
    $result = (new HealthCheckRunnerService($client, new DateTimeImmutable('2026-11-06', new DateTimeZone('UTC'))))->run(
        $root,
        ExcubiaConfigurationData::defaults(),
        ['composer-abandoned', 'composer-stale', 'composer-outdated'],
        null,
        false,
        true,
    );
    $checkIds = array_map(static fn (CheckResultData $checkResult): string => $checkResult->checkId, $result['report']->checkResults);
    $outdated = $result['report']->checkResults[2];
    $planMessages = array_map(static fn ($step): string => $step->message, $result['report']->upgradeSteps);

    expect($checkIds)->toBe(['composer-abandoned', 'composer-stale', 'composer-outdated'])
        ->and(array_count_values($client->requests)['GET '.MONOLOG_P2_URL.' []'])->toBe(1)
        ->and($outdated->findings[0]->details['latestMajor'])->toBe('3.8.1')
        ->and($planMessages)->toContain('Update to the latest patch: monolog/monolog 2.9.3.');
});
