<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\NpmOutdatedCheck;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Enums\SkipReasonEnum;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Renderers\SarifRenderer;
use Qiick\Excubia\Services\ComposerConstraintService;
use Qiick\Excubia\Services\HealthCheckRunnerService;
use Qiick\Excubia\Services\NpmRegistryService;
use Qiick\Excubia\Services\ProjectSnapshotService;
use Qiick\Excubia\Services\ResponseCacheService;
use Qiick\Excubia\Services\SymfonyHttpClientService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

const AXIOS_REGISTRY_URL = 'https://registry.npmjs.org/axios';
const TYPES_NODE_REGISTRY_URL = 'https://registry.npmjs.org/@types%2Fnode';

/**
 * A recorded abbreviated-metadata answer from `tests/Fixtures/Responses/`.
 * axios: 0.27.2, 0.28.0, 0.28.1, 1.0.0-alpha.1, 1.0.0, 1.6.8, 1.7.0,
 * 1.7.8, 1.7.9 (latest). @types/node: 20.11.0, 20.17.10, 22.9.0, 22.10.2.
 *
 * @return array<string, mixed>
 */
function npmRegistryResponse(string $fileName): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Responses/'.$fileName), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param  list<string>  $versions
 * @return array<string, mixed>
 */
function npmRegistryDocument(array $versions, string $latest): array
{
    return ['dist-tags' => ['latest' => $latest], 'versions' => array_fill_keys($versions, [])];
}

/**
 * @param  array<string, array<int|string, mixed>>  $responses
 * @param  list<LockedPackageData>  $packages
 * @param  list<string>  $developmentNames
 * @param  array<string, DataSourceUnavailableException>  $failures
 */
function npmOutdatedRun(
    array $responses,
    array $packages,
    ?FakeHttpClient &$client = null,
    ?ExcubiaConfigurationData $configuration = null,
    array $developmentNames = [],
    bool $isOffline = false,
    array $failures = [],
    bool $hasLockfile = true,
): CheckResultData {
    $client = new FakeHttpClient($responses, $failures);
    $check = new NpmOutdatedCheck(new NpmRegistryService($client, new ResponseCacheService(null, 24, $isOffline, true)), new ComposerConstraintService);
    $snapshot = new ProjectSnapshotData(null, null, [], $packages, null, hasNpmLockfile: $hasLockfile, javascriptLockfile: 'yarn.lock', npmDevelopmentPackageNames: $developmentNames);

    return $check->run($snapshot, $configuration ?? ExcubiaConfigurationData::defaults());
}

function withoutDevelopmentDependencies(): ExcubiaConfigurationData
{
    $defaults = ExcubiaConfigurationData::defaults();

    return new ExcubiaConfigurationData(
        failOnSeverity: $defaults->failOnSeverity,
        isDevelopmentDependencyIncluded: false,
        staleAfterMonths: 24,
        phpWarnDaysBeforeEndOfLife: 180,
        laravelWarnDaysBeforeEndOfLife: 180,
        isNodeEndOfLifeCheckEnabled: true,
        ignoreRules: [],
        cacheDirectory: null,
        cacheTtlHours: 24,
    );
}

it('reports a direct dependency behind on a major with the composer-outdated details', function () {
    $result = npmOutdatedRun([AXIOS_REGISTRY_URL => npmRegistryResponse('npm-registry-axios.json')], [new LockedPackageData('axios', '0.27.2', true, false)], $client);
    $finding = $result->findings[0];

    expect($result->status)->toBe(FindingStatusEnum::Warn)
        ->and($result->findings)->toHaveCount(1)
        ->and($finding->checkId)->toBe('npm-outdated')
        ->and($finding->severity)->toBe(SeverityEnum::Low)
        ->and($finding->subject)->toBe('axios')
        ->and($finding->message)->toBe('A new major is available: 1.7.9.')
        ->and($finding->fixHint)->toBe('Update to 0.28.1, then plan the upgrade to 1.7.9.')
        ->and($finding->evidenceUrl)->toBe('https://www.npmjs.com/package/axios')
        ->and($finding->details)->toBe([
            'package' => 'axios',
            'lockedVersion' => '0.27.2',
            'latestPatch' => null,
            'latestMinor' => '0.28.1',
            'latestMajor' => '1.7.9',
            'isMajorBehind' => true,
        ])
        ->and($client->requests)->toBe(['GET '.AXIOS_REGISTRY_URL.' []'])
        ->and($client->lastHeaders)->toBe(['Accept' => 'application/vnd.npm.install-v1+json']);
});

it('reports a minor behind at low, a patch behind at info and the newest release as passing', function () {
    $axios = [AXIOS_REGISTRY_URL => npmRegistryResponse('npm-registry-axios.json')];
    $minor = npmOutdatedRun($axios, [new LockedPackageData('axios', '1.6.8', true, false)])->findings[0];
    $patch = npmOutdatedRun($axios, [new LockedPackageData('axios', '1.7.8', true, false)])->findings[0];
    $newest = npmOutdatedRun($axios, [new LockedPackageData('axios', '1.7.9', true, false)]);

    expect($minor->severity)->toBe(SeverityEnum::Low)
        ->and($minor->message)->toBe('Behind on a minor: 1.7.9 is available.')
        ->and($minor->details['latestPatch'])->toBeNull()
        ->and($patch->severity)->toBe(SeverityEnum::Info)
        ->and($patch->message)->toBe('Behind on a patch: 1.7.9 is available.')
        ->and($patch->details['latestPatch'])->toBe('1.7.9')
        ->and($newest->status)->toBe(FindingStatusEnum::Pass)
        ->and($newest->findings)->toBe([]);
});

it('encodes a scoped name and sends nothing but the name', function () {
    $result = npmOutdatedRun([TYPES_NODE_REGISTRY_URL => npmRegistryResponse('npm-registry-types-node.json')], [new LockedPackageData('@types/node', '20.11.0', true, true)], $client);

    expect($result->findings[0]->details)->toBe([
        'package' => '@types/node',
        'lockedVersion' => '20.11.0',
        'latestPatch' => null,
        'latestMinor' => '20.17.10',
        'latestMajor' => '22.10.2',
        'isMajorBehind' => true,
    ])
        ->and($client->requests)->toBe(['GET '.TYPES_NODE_REGISTRY_URL.' []'])
        ->and($client->lastQuery)->toBe([]);
});

it('ignores pre-releases unless the locked version is one, and stable releases above the latest dist-tag', function () {
    $document = [AXIOS_REGISTRY_URL => npmRegistryDocument(['1.0.0', '1.1.0', '2.0.0-beta.1', '2.0.0-beta.3', '3.0.0'], '1.1.0')];
    $stable = npmOutdatedRun($document, [new LockedPackageData('axios', '1.0.0', true, false)])->findings[0];
    $preRelease = npmOutdatedRun($document, [new LockedPackageData('axios', '2.0.0-beta.1', true, false)])->findings[0];

    expect($stable->details)->toMatchArray(['latestMinor' => '1.1.0', 'latestMajor' => null, 'isMajorBehind' => false])
        ->and($preRelease->details)->toMatchArray(['latestPatch' => '2.0.0-beta.3', 'latestMinor' => '2.0.0-beta.3', 'latestMajor' => null]);
});

it('leaves out transitive packages, unreadable versions and, under --no-dev, dev-only dependencies', function () {
    $packages = [
        new LockedPackageData('axios', '0.27.2', false, false),
        new LockedPackageData('@types/node', '20.11.0', true, true),
        new LockedPackageData('canary', '1.0.0-canary.abc+sha', true, false),
    ];
    $withDev = npmOutdatedRun([TYPES_NODE_REGISTRY_URL => npmRegistryResponse('npm-registry-types-node.json')], $packages, $clientWithDev, developmentNames: ['@types/node']);
    $withoutDev = npmOutdatedRun([], $packages, $clientWithoutDev, withoutDevelopmentDependencies(), ['@types/node']);

    expect($withDev->findings)->toHaveCount(1)
        ->and($clientWithDev->requests)->toBe(['GET '.TYPES_NODE_REGISTRY_URL.' []'])
        ->and($withoutDev->status)->toBe(FindingStatusEnum::Pass)
        ->and($clientWithoutDev->requests)->toBe([]);
});

it('compares the highest locked copy of a direct name once', function () {
    $result = npmOutdatedRun([AXIOS_REGISTRY_URL => npmRegistryResponse('npm-registry-axios.json')], [
        new LockedPackageData('axios', '0.27.2', true, false),
        new LockedPackageData('axios', '1.7.8', true, false),
    ], $client);

    expect($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->details['lockedVersion'])->toBe('1.7.8')
        ->and($client->requests)->toHaveCount(1);
});

it('skips private direct dependencies without sending their names', function () {
    $configuration = new ExcubiaConfigurationData(
        failOnSeverity: SeverityEnum::High,
        isDevelopmentDependencyIncluded: true,
        staleAfterMonths: 24,
        phpWarnDaysBeforeEndOfLife: 180,
        laravelWarnDaysBeforeEndOfLife: 180,
        isNodeEndOfLifeCheckEnabled: true,
        ignoreRules: [],
        cacheDirectory: null,
        cacheTtlHours: 24,
        skippedPackagePatterns: ['@acme/*'],
    );
    $result = npmOutdatedRun([], [
        new LockedPackageData('@acme/ui', '1.0.0', true, false),
        new LockedPackageData('internal-tool', '1.0.0', true, false, isPrivate: true),
        new LockedPackageData('internal-tool', '1.0.0', true, false),
    ], $client, $configuration);

    expect($result->status)->toBe(FindingStatusEnum::Skipped)
        ->and($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->message)->toBe('2 private packages not sent to the npm registry: @acme/ui, internal-tool.')
        ->and($result->findings[0]->skipReason)->toBe(SkipReasonEnum::NotApplicable)
        ->and($client->requests)->toBe([]);
});

it('skips without a JavaScript lockfile', function () {
    $result = npmOutdatedRun([], [], $client, hasLockfile: false);

    expect($result->status)->toBe(FindingStatusEnum::Skipped)
        ->and($result->findings[0]->skipReason)->toBe(SkipReasonEnum::NotApplicable)
        ->and($client->requests)->toBe([]);
});

it('skips as a data-source failure when the registry does not answer, and passes a package it does not know', function () {
    $failed = npmOutdatedRun([], [new LockedPackageData('axios', '0.27.2', true, false)]);
    $unknown = npmOutdatedRun([], [new LockedPackageData('axios', '0.27.2', true, false)], failures: [
        AXIOS_REGISTRY_URL => new DataSourceUnavailableException('http', 'The data source returned HTTP 404.', httpStatus: 404),
    ]);
    $invalidName = npmOutdatedRun([], [new LockedPackageData('../etc', '1.0.0', true, false)], $invalidNameClient);

    expect($failed->status)->toBe(FindingStatusEnum::Skipped)
        ->and($failed->findings[0]->skipReason)->toBe(SkipReasonEnum::DataSourceFailure)
        ->and($failed->findings[0]->message)->toBe('The npm registry did not respond.')
        ->and($unknown->status)->toBe(FindingStatusEnum::Pass)
        ->and($invalidName->findings[0]->skipReason)->toBe(SkipReasonEnum::DataSourceFailure)
        ->and($invalidNameClient->requests)->toBe([]);
});

it('sends no request under --offline and reports the skip as offline', function () {
    $client = new FakeHttpClient([AXIOS_REGISTRY_URL => npmRegistryResponse('npm-registry-axios.json')]);
    $snapshot = new ProjectSnapshotData(null, null, [], [new LockedPackageData('axios', '0.27.2', true, false)], null, hasNpmLockfile: true);
    $report = (new HealthCheckRunnerService($client, new DateTimeImmutable('2026-11-06')))
        ->runForSnapshot($snapshot, ExcubiaConfigurationData::defaults(), ['npm-outdated'], null, true, false)['report'];

    expect($report->checkResults[0]->findings[0]->skipReason)->toBe(SkipReasonEnum::Offline)
        ->and($client->requests)->toBe([]);
});

it('points SARIF results at the JavaScript lockfile the report read', function () {
    $client = new FakeHttpClient([AXIOS_REGISTRY_URL => npmRegistryResponse('npm-registry-axios.json')]);
    $snapshot = new ProjectSnapshotData(null, null, [], [new LockedPackageData('axios', '0.27.2', true, false)], null, hasNpmLockfile: true, javascriptLockfile: 'pnpm-lock.yaml');
    $report = (new HealthCheckRunnerService($client, new DateTimeImmutable('2026-11-06')))
        ->runForSnapshot($snapshot, ExcubiaConfigurationData::defaults(), ['npm-outdated'], null, false, true)['report'];
    $result = (new SarifRenderer)->toArray($report)['runs'][0]['results'][0];

    expect($result['ruleId'])->toBe('npm-outdated')
        ->and($result['locations'][0]['physicalLocation']['artifactLocation']['uri'])->toBe('pnpm-lock.yaml');
});

it('reads the dev-only names from package.json', function () {
    $root = temporaryProject([
        'composer.lock' => '{"packages": [], "packages-dev": []}',
        'package.json' => json_encode([
            'dependencies' => ['axios' => '^1.7.0'],
            'optionalDependencies' => ['fsevents' => '^2.3.0'],
            'devDependencies' => ['vite' => '^6.0.0', 'axios' => '^1.7.0', 'fsevents' => '^2.3.0'],
        ], JSON_THROW_ON_ERROR),
        'package-lock.json' => json_encode(['lockfileVersion' => 3, 'packages' => ['' => []]], JSON_THROW_ON_ERROR),
    ]);
    $withoutLockfile = temporaryProject([
        'composer.lock' => '{"packages": [], "packages-dev": []}',
        'package.json' => '{"devDependencies": {"vite": "^6.0.0"}}',
    ]);

    expect((new ProjectSnapshotService)->snapshot($root, ExcubiaConfigurationData::defaults())->npmDevelopmentPackageNames)->toBe(['vite'])
        ->and((new ProjectSnapshotService)->snapshot($withoutLockfile, ExcubiaConfigurationData::defaults())->npmDevelopmentPackageNames)->toBe([]);
});

it('asks the registry for abbreviated metadata through the Symfony client', function () {
    $requestHeaders = [];
    $mock = (new MockHttpClient(function (string $method, string $url, array $options) use (&$requestHeaders): MockResponse {
        $requestHeaders = $options['headers'];

        return new MockResponse((string) json_encode(npmRegistryDocument(['1.0.0'], '1.0.0')));
    }))->withOptions(SymfonyHttpClientService::defaultOptions());
    $client = new SymfonyHttpClientService($mock);
    $metadata = (new NpmRegistryService($client, new ResponseCacheService(null, 24, false, true)))->metadata('left-pad');

    expect($metadata->versions)->toBe(['1.0.0'])
        ->and($metadata->latestVersion)->toBe('1.0.0')
        ->and($requestHeaders)->toContain('Accept: application/vnd.npm.install-v1+json', 'User-Agent: qiick-excubia')
        ->and($requestHeaders)->not->toContain('Accept: application/json');
});
