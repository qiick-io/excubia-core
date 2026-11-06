<?php

declare(strict_types=1);

use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SkipReasonEnum;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Renderers\JsonReportRenderer;
use Qiick\Excubia\Services\HealthCheckRunnerService;
use Qiick\Excubia\Services\ProjectSnapshotService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Tester\CommandTester;

const PACKAGIST_DOWNLOADS = 'https://packagist.org/downloads/';

/**
 * A project mixing public packages with every private origin the lockfile
 * heuristic knows, plus one public package hidden by `privacy.skipPackages`.
 *
 * @return array<string, string>
 */
function privatePackagesProject(): array
{
    $composerLock = [
        'packages' => [
            ['name' => 'laravel/framework', 'version' => 'v12.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/laravel/framework/zipball/abc'], 'notification-url' => PACKAGIST_DOWNLOADS],
            ['name' => 'symfony/console', 'version' => 'v7.2.0', 'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/symfony/console/zipball/abc'], 'notification-url' => PACKAGIST_DOWNLOADS],
            ['name' => 'acme/path-package', 'version' => 'dev-main', 'dist' => ['type' => 'path', 'url' => 'packages/path-package']],
            ['name' => 'acme/artifact-package', 'version' => '1.0.0', 'dist' => ['type' => 'artifact', 'url' => 'artifacts/acme.zip'], 'notification-url' => PACKAGIST_DOWNLOADS],
            ['name' => 'acme/satis-package', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://satis.acme.test/dist/acme-satis-package.zip']],
            ['name' => 'acme/private-packagist', 'version' => '1.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://acme.repo.packagist.com/dist/x.zip'], 'notification-url' => 'https://acme.repo.packagist.com/downloads/'],
            ['name' => 'acme/vcs-package', 'version' => 'dev-main', 'source' => ['type' => 'git', 'url' => 'https://github.com/acme/vcs-package.git']],
            ['name' => 'internal/tooling', 'version' => '1.0.0', 'notification-url' => PACKAGIST_DOWNLOADS],
        ],
        'packages-dev' => [],
    ];
    $packageLock = [
        'name' => 'fixture',
        'lockfileVersion' => 3,
        'packages' => [
            '' => ['name' => 'fixture'],
            'node_modules/lodash' => ['version' => '4.17.20', 'resolved' => 'https://registry.npmjs.org/lodash/-/lodash-4.17.20.tgz'],
            'node_modules/@acme/linked' => ['version' => '1.0.0', 'resolved' => 'packages/linked', 'link' => true],
            'packages/ui' => ['name' => '@acme/ui', 'version' => '1.0.0'],
            'node_modules/@acme/tarball' => ['version' => '1.0.0', 'resolved' => 'file:vendor/acme-tarball-1.0.0.tgz'],
            'node_modules/@corp/registry' => ['version' => '1.0.0', 'resolved' => 'https://npm.corp.test/@corp/registry/-/registry-1.0.0.tgz'],
            'node_modules/@scope/hidden' => ['version' => '1.0.0', 'resolved' => 'https://registry.npmjs.org/@scope/hidden/-/hidden-1.0.0.tgz'],
        ],
    ];

    return [
        'composer.json' => json_encode(['require' => [
            'laravel/framework' => '^12.0',
            'symfony/console' => '^7.2',
            'acme/path-package' => '*',
            'acme/satis-package' => '^1.0',
        ]], JSON_THROW_ON_ERROR),
        'composer.lock' => json_encode($composerLock, JSON_THROW_ON_ERROR),
        'package-lock.json' => json_encode($packageLock, JSON_THROW_ON_ERROR),
        'excubia.json' => '{"privacy": {"skipPackages": ["internal/*", "@scope/hid?en"]}}',
    ];
}

/**
 * @return array<string, mixed>
 */
function packagistMetadata(string $name): array
{
    return ['packages' => [$name => [['version' => 'v99.0.0', 'time' => '2026-10-01T00:00:00+00:00', 'require' => []]]]];
}

function privatePackagesRun(FakeHttpClient $client): array
{
    $root = temporaryProject(privatePackagesProject());
    $configuration = (new ExcubiaConfigurationLoader)->load(null, $root);
    $configuration = new ExcubiaConfigurationData(
        failOnSeverity: $configuration->failOnSeverity,
        isDevelopmentDependencyIncluded: true,
        staleAfterMonths: 24,
        phpWarnDaysBeforeEndOfLife: 180,
        laravelWarnDaysBeforeEndOfLife: 180,
        isNodeEndOfLifeCheckEnabled: true,
        ignoreRules: [],
        cacheDirectory: $root.'/cache',
        cacheTtlHours: 24,
        skippedPackagePatterns: $configuration->skippedPackagePatterns,
    );

    $result = (new HealthCheckRunnerService($client, new DateTimeImmutable('2026-11-03', new DateTimeZone('UTC'))))->run(
        $root,
        $configuration,
        ['composer-advisories', 'npm-advisories', 'composer-abandoned', 'composer-stale', 'laravel-next-blocker'],
        null,
        false,
        true,
    );

    $byId = [];

    foreach ($result['report']->checkResults as $checkResult) {
        $byId[$checkResult->checkId] = $checkResult;
    }

    return ['checks' => $byId, 'report' => $result['report']];
}

function publicDataSourceClient(): FakeHttpClient
{
    return new FakeHttpClient([
        'https://packagist.org/api/security-advisories/' => ['advisories' => []],
        'https://repo.packagist.org/p2/laravel/framework.json' => packagistMetadata('laravel/framework'),
        'https://repo.packagist.org/p2/symfony/console.json' => packagistMetadata('symfony/console'),
        'https://api.osv.dev/v1/querybatch' => ['results' => [['vulns' => []]]],
    ]);
}

function privateSkipFinding(CheckResultData $checkResult): FindingData
{
    foreach ($checkResult->findings as $finding) {
        if ($finding->subject === 'private packages') {
            return $finding;
        }
    }

    throw new RuntimeException('No private packages row in '.$checkResult->checkId.'.');
}

it('marks composer packages private unless Packagist served them', function () {
    $root = temporaryProject(privatePackagesProject());
    $snapshot = (new ProjectSnapshotService)->snapshot($root, ExcubiaConfigurationData::defaults());
    $isPrivate = [];

    foreach ($snapshot->composerPackages as $package) {
        $isPrivate[$package->name] = $package->isPrivate;
    }

    expect($isPrivate)->toBe([
        'laravel/framework' => false,
        'symfony/console' => false,
        'acme/path-package' => true,
        'acme/artifact-package' => true,
        'acme/satis-package' => true,
        'acme/private-packagist' => true,
        'acme/vcs-package' => true,
        'internal/tooling' => false,
    ]);
});

it('marks npm packages private unless the public registry served them', function () {
    $root = temporaryProject(privatePackagesProject());
    $snapshot = (new ProjectSnapshotService)->snapshot($root, ExcubiaConfigurationData::defaults());
    $isPrivate = [];

    foreach ($snapshot->npmPackages as $package) {
        $isPrivate[$package->name] = $package->isPrivate;
    }

    expect($isPrivate)->toBe([
        'lodash' => false,
        '@acme/linked' => true,
        '@acme/ui' => true,
        '@acme/tarball' => true,
        '@corp/registry' => true,
        '@scope/hidden' => false,
    ]);
});

it('never sends a private package name to Packagist, OSV or GitHub', function () {
    $client = publicDataSourceClient();
    privatePackagesRun($client);
    $sent = implode("\n", $client->requests);

    expect($sent)->not->toContain('acme')
        ->and($sent)->not->toContain('internal/tooling')
        ->and($sent)->not->toContain('@corp')
        ->and($sent)->not->toContain('@scope')
        ->and($sent)->not->toContain('api.github.com')
        ->and($sent)->toContain('GET https://packagist.org/api/security-advisories/ {"packages":["laravel/framework","symfony/console"]}')
        ->and($sent)->toContain('GET https://repo.packagist.org/p2/laravel/framework.json')
        ->and($sent)->toContain('GET https://repo.packagist.org/p2/symfony/console.json')
        ->and($sent)->toContain('POST https://api.osv.dev/v1/querybatch {"queries":[{"package":{"name":"lodash","ecosystem":"npm"},"version":"4.17.20"}]}');
});

it('reports the private packages as one not-applicable skipped row per check', function () {
    $run = privatePackagesRun(publicDataSourceClient());
    $checks = $run['checks'];
    $composerNames = 'acme/path-package, acme/artifact-package, acme/satis-package, acme/private-packagist, acme/vcs-package, internal/tooling';

    foreach (['composer-advisories', 'npm-advisories', 'composer-abandoned', 'composer-stale', 'laravel-next-blocker'] as $checkId) {
        expect($checks[$checkId]->status)->toBe(FindingStatusEnum::Skipped)
            ->and($checks[$checkId]->findings)->toHaveCount(1)
            ->and(privateSkipFinding($checks[$checkId])->skipReason)->toBe(SkipReasonEnum::NotApplicable);
    }

    expect(privateSkipFinding($checks['composer-advisories'])->message)->toBe('6 private packages not sent to Packagist: '.$composerNames.'.')
        ->and(privateSkipFinding($checks['composer-abandoned'])->message)->toBe('6 private packages not sent to Packagist: '.$composerNames.'.')
        ->and(privateSkipFinding($checks['composer-stale'])->message)->toBe('6 private packages not sent to Packagist or GitHub: '.$composerNames.'.')
        ->and(privateSkipFinding($checks['laravel-next-blocker'])->message)->toBe('2 private packages not sent to Packagist: acme/path-package, acme/satis-package.')
        ->and(privateSkipFinding($checks['npm-advisories'])->message)->toBe('5 private packages not sent to OSV.dev: @acme/linked, @acme/ui, @acme/tarball, @corp/registry, @scope/hidden.')
        ->and((new JsonReportRenderer)->render($run['report']))->toContain('"skipReason": "not-applicable"');
});

it('keeps real findings next to the private packages row', function () {
    $client = new FakeHttpClient([
        'https://packagist.org/api/security-advisories/' => ['advisories' => ['symfony/console' => [[
            'advisoryId' => 'PKSA-console',
            'packageName' => 'symfony/console',
            'affectedVersions' => '<8.0.0',
            'severity' => 'high',
        ]]]],
    ]);
    $checks = privatePackagesRun($client)['checks'];

    expect($checks['composer-advisories']->status)->toBe(FindingStatusEnum::Fail)
        ->and($checks['composer-advisories']->findings)->toHaveCount(2)
        ->and(privateSkipFinding($checks['composer-advisories'])->status)->toBe(FindingStatusEnum::Skipped);
});

it('names a single private package in the singular', function () {
    expect(FindingData::privatePackagesSkipped('composer-advisories', ['acme/one'], 'Packagist')[0]->message)
        ->toBe('1 private package not sent to Packagist: acme/one.')
        ->and(FindingData::privatePackagesSkipped('composer-advisories', [], 'Packagist'))->toBe([]);
});

it('matches privacy.skipPackages globs on the whole name, case-insensitively', function (string $pattern, string $name, bool $isPrivate) {
    $configuration = new ExcubiaConfigurationData(
        failOnSeverity: null,
        isDevelopmentDependencyIncluded: true,
        staleAfterMonths: 24,
        phpWarnDaysBeforeEndOfLife: 180,
        laravelWarnDaysBeforeEndOfLife: 180,
        isNodeEndOfLifeCheckEnabled: true,
        ignoreRules: [],
        cacheDirectory: null,
        cacheTtlHours: 24,
        skippedPackagePatterns: [$pattern],
    );

    expect($configuration->isPrivatePackage(new LockedPackageData($name, '1.0.0', true, false)))->toBe($isPrivate);
})->with([
    'vendor wildcard' => ['acme/*', 'acme/billing', true],
    'other vendor' => ['acme/*', 'acmecorp/billing', false],
    'npm scope' => ['@acme/*', '@acme/ui', true],
    'npm scope does not match unscoped' => ['@acme/*', 'acme-ui', false],
    'single character' => ['acme/v?', 'acme/v2', true],
    'single character is exactly one' => ['acme/v?', 'acme/v10', false],
    'exact name' => ['acme/billing', 'ACME/Billing', true],
    'dot is literal' => ['acme.ui', 'acmexui', false],
    'star crosses the slash' => ['*billing*', 'acme/billing-core', true],
]);

it('loads privacy.skipPackages and defaults it to an empty list', function () {
    $loader = new ExcubiaConfigurationLoader;

    expect($loader->load(null, temporaryProject(['excubia.json' => '{"privacy": {"skipPackages": ["acme/*", "@acme/*"]}}']))->skippedPackagePatterns)
        ->toBe(['acme/*', '@acme/*'])
        ->and($loader->load(null, temporaryProject(['excubia.json' => '{"privacy": {}}']))->skippedPackagePatterns)->toBe([])
        ->and(ExcubiaConfigurationData::defaults()->skippedPackagePatterns)->toBe([]);
});

it('rejects an invalid privacy key', function (string $document, string $message) {
    $root = temporaryProject(['excubia.json' => $document]);

    expect(fn () => (new ExcubiaConfigurationLoader)->load(null, $root))
        ->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'not an object' => ['{"privacy": true}', 'privacy must be an object.'],
    'unknown key' => ['{"privacy": {"skipVendors": []}}', 'Unknown privacy key [skipVendors].'],
    'not a list' => ['{"privacy": {"skipPackages": "acme/*"}}', 'privacy.skipPackages must be a list of strings.'],
    'empty string' => ['{"privacy": {"skipPackages": [""]}}', 'privacy.skipPackages[0] must be a non-empty string.'],
    'not a string' => ['{"privacy": {"skipPackages": [1]}}', 'privacy.skipPackages[0] must be a non-empty string.'],
    'character class' => ['{"privacy": {"skipPackages": ["acme/[ab]*"]}}', 'privacy.skipPackages[0] must be a package name glob'],
    'brace expansion' => ['{"privacy": {"skipPackages": ["acme/*", "{acme,corp}/*"]}}', 'privacy.skipPackages[1] must be a package name glob'],
    'whitespace' => ['{"privacy": {"skipPackages": ["acme/ *"]}}', 'privacy.skipPackages[0] must be a package name glob'],
]);

it('documents privacy.skipPackages in the closed schema', function () {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/excubia.schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $privacy = $schema['properties']['privacy'];

    expect($privacy['additionalProperties'])->toBeFalse()
        ->and($privacy['properties']['skipPackages']['items']['pattern'])->toBe('^[A-Za-z0-9@/._~*?-]+$');
});

it('keeps privacy.skipPackages when --fail-on or --no-dev override the file', function () {
    $client = new FakeHttpClient([]);
    $tester = new CommandTester(new CheckCommand($client));
    $tester->execute([
        'path' => temporaryProject(privatePackagesProject()),
        '--format' => 'json',
        '--no-banner' => true,
        '--fail-on' => 'none',
        '--no-dev' => true,
        '--only' => 'composer-abandoned',
    ]);

    expect(implode("\n", $client->requests))->not->toContain('internal/tooling')
        ->and(implode("\n", $client->requests))->toContain('laravel/framework');
});
