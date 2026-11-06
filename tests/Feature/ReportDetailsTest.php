<?php

declare(strict_types=1);

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\EndOfLifeCycleData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\HealthReportData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Data\ProjectVersionsData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Renderers\JsonReportRenderer;
use Qiick\Excubia\Renderers\SarifRenderer;
use Qiick\Excubia\Services\HealthCheckRunnerService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Validate a report against `docs/report-schema.json`.
 *
 * justinrainbow/json-schema has no 2020-12 dialect and falls back to draft 4,
 * which ignores `if` / `then` and `const`. Every keyword the schema uses means
 * the same in draft 7, so the test validates under draft 7 in strict mode to
 * make the per-check `details` rules count.
 *
 * @param  array<string, mixed>  $report
 * @return list<string>
 */
function reportSchemaErrors(array $report): array
{
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/docs/report-schema.json'), false, 512, JSON_THROW_ON_ERROR);
    $schema->{'$schema'} = 'http://json-schema.org/draft-07/schema#';
    $document = json_decode(json_encode($report, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $validator = new Validator;
    $validator->validate($document, $schema, Constraint::CHECK_MODE_STRICT);

    return array_map(fn (array $error): string => $error['property'].': '.$error['message'], $validator->getErrors());
}

/**
 * `excubia check --format=json` on the outdated Laravel 10 fixture with
 * recorded answers; URLs without an entry make their check skip.
 *
 * @return array<string, mixed>
 */
function detailedJsonReport(): array
{
    $client = new FakeHttpClient([
        'https://endoflife.date/api/php.json' => [
            ['cycle' => '8.4', 'eol' => '2028-12-31', 'support' => '2026-12-31', 'lts' => false],
            ['cycle' => '8.1', 'eol' => '2025-12-31', 'support' => '2023-11-25', 'lts' => false],
        ],
        'https://endoflife.date/api/laravel.json' => [
            ['cycle' => '13', 'eol' => '2028-03-17', 'support' => '2027-09-17', 'lts' => false],
            ['cycle' => '10', 'eol' => '2025-02-04', 'support' => '2024-08-06', 'lts' => false],
        ],
        'https://packagist.org/api/security-advisories/' => [
            'advisories' => [
                'laravel/framework' => [[
                    'advisoryId' => 'PKSA-2y3q-xh2b-7vqk',
                    'packageName' => 'laravel/framework',
                    'affectedVersions' => '>=10.0.0,<10.48.23',
                    'link' => 'https://github.com/advisories/GHSA-gv7v-rgg6-548h',
                    'severity' => 'medium',
                ]],
            ],
        ],
    ]);
    $tester = new CommandTester(new CheckCommand($client, new DateTimeImmutable('2026-10-31 07:30:15', new DateTimeZone('UTC'))));
    $tester->execute([
        'path' => dirname(__DIR__).'/Fixtures/Projects/outdated-laravel-10',
        '--format' => 'json',
        '--fail-on' => 'none',
        '--no-banner' => true,
        '--no-cache' => true,
    ]);

    return json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function firstFindingOf(array $report, string $checkId): array
{
    foreach ($report['checks'] as $check) {
        if ($check['checkId'] === $checkId) {
            return $check['findings'][0];
        }
    }

    throw new RuntimeException('No '.$checkId.' check in the report.');
}

it('adds generatedAt, excubiaVersion and the project versions at the top level', function () {
    $report = detailedJsonReport();

    expect(array_keys($report))->toBe(['schemaVersion', 'generatedAt', 'excubiaVersion', 'project', 'checks', 'upgradePlan', 'licenses'])
        ->and($report['schemaVersion'])->toBe(1)
        ->and($report['generatedAt'])->toBe('2026-10-31T07:30:15Z')
        ->and($report['excubiaVersion'])->toBe(HealthCheckRunnerService::excubiaVersion())
        ->and($report['project'])->toBe([
            'php' => ['version' => '8.1.0', 'cycle' => '8.1', 'securitySupportEndsOn' => '2025-12-31', 'activeSupportEndsOn' => '2023-11-25'],
            'laravel' => ['version' => 'v10.48.22', 'cycle' => '10', 'securitySupportEndsOn' => '2025-02-04', 'activeSupportEndsOn' => '2024-08-06'],
            'node' => null,
        ]);
});

it('produces a report that validates against docs/report-schema.json', function () {
    expect(reportSchemaErrors(detailedJsonReport()))->toBe([]);
});

it('emits the advisory id, the details and the SARIF fingerprint per finding', function () {
    $report = detailedJsonReport();
    $advisory = firstFindingOf($report, 'composer-advisories');
    $endOfLife = firstFindingOf($report, 'laravel-eol');

    expect($advisory['advisoryId'])->toBe('PKSA-2y3q-xh2b-7vqk')
        ->and($advisory['details'])->toBe([
            'package' => 'laravel/framework',
            'lockedVersion' => 'v10.48.22',
            'affectedVersions' => '>=10.0.0,<10.48.23',
            'fixedVersions' => ['10.48.23'],
        ])
        ->and($advisory['fingerprint'])->toBe(hash('sha256', "composer-advisories\0laravel/framework\0PKSA-2y3q-xh2b-7vqk"))
        ->and($endOfLife['details'])->toBe(['cycle' => '10', 'securitySupportEndsOn' => '2025-02-04', 'activeSupportEndsOn' => '2024-08-06'])
        ->and($endOfLife)->not->toHaveKey('advisoryId');
});

it('uses the same fingerprint as the SARIF partialFingerprints entry', function () {
    $finding = new FindingData('composer-advisories', SeverityEnum::High, FindingStatusEnum::Fail, 'vendor/package', 'Known vulnerability PKSA-1.', 'Update.', null, 'PKSA-1');
    $report = new HealthReportData([CheckResultData::fromFindings('composer-advisories', 'Known vulnerabilities (Composer)', [$finding])], []);
    $sarif = (new SarifRenderer)->toArray($report);
    $json = (new JsonReportRenderer)->toArray($report);

    expect($json['checks'][0]['findings'][0]['fingerprint'])->toBe($sarif['runs'][0]['results'][0]['partialFingerprints']['excubiaFinding/v1'])
        ->and($finding->fingerprint())->toBe(hash('sha256', "composer-advisories\0vendor/package\0PKSA-1"));
});

it('never writes the absolute project path or the hostname into the report', function () {
    $json = json_encode(detailedJsonReport(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $hostname = gethostname();

    expect($json)->not->toContain(dirname(__DIR__))
        ->and($json)->not->toContain(sys_get_temp_dir());

    if (is_string($hostname) && strlen($hostname) > 3) {
        expect($json)->not->toContain($hostname);
    }
});

it('omits the top-level fields from a hand-built report and keeps them through ignore rules', function () {
    $bare = (new JsonReportRenderer)->toArray(new HealthReportData([], []));
    $full = new HealthReportData([], [], new DateTimeImmutable('2026-11-03 10:00:00', new DateTimeZone('Europe/Rome')), '1.0.0-beta', new ProjectVersionsData('8.3.0', 'v12.0.0', '22'));
    $filtered = $full->withoutIgnoredFindings(ExcubiaConfigurationData::defaults(), new DateTimeImmutable('2026-11-03'));
    $rendered = (new JsonReportRenderer)->toArray($filtered);

    expect(array_keys($bare))->toBe(['schemaVersion', 'checks', 'upgradePlan'])
        ->and($rendered['generatedAt'])->toBe('2026-11-03T09:00:00Z')
        ->and($rendered['excubiaVersion'])->toBe('1.0.0-beta')
        ->and($rendered['project'])->toBe(['php' => ['version' => '8.3.0', 'cycle' => null, 'securitySupportEndsOn' => null, 'activeSupportEndsOn' => null], 'laravel' => ['version' => 'v12.0.0', 'cycle' => null, 'securitySupportEndsOn' => null, 'activeSupportEndsOn' => null], 'node' => ['version' => '22', 'cycle' => null, 'securitySupportEndsOn' => null, 'activeSupportEndsOn' => null]]);
});

it('reports a Node declaration as written and drops free text', function () {
    $versions = static fn (?string $declaration): ?string => ProjectVersionsData::fromSnapshot(new ProjectSnapshotData('8.3.0', null, [], [], $declaration))->node;

    expect($versions('20.11.1'))->toBe('20.11.1')
        ->and($versions('>=18 <23'))->toBe('>=18 <23')
        ->and($versions('lts/iron'))->toBe('lts/iron')
        ->and($versions(null))->toBeNull()
        ->and($versions('see https://example.test?token=abc'))->toBeNull()
        ->and($versions(str_repeat('1', 65)))->toBeNull();
});

it('counts the private packages in the details of their skipped row', function () {
    expect(FindingData::privatePackagesSkipped('composer-stale', ['acme/a', 'acme/b'], 'Packagist')[0]->details)->toBe(['count' => 2]);
});

it('accepts every documented details shape and rejects one that does not fit its check', function () {
    $finding = static fn (string $checkId, array $details): FindingData => new FindingData($checkId, SeverityEnum::Medium, FindingStatusEnum::Warn, 'subject', 'Message.', '', null, details: $details);
    $render = static fn (array $findings): array => (new JsonReportRenderer)->toArray(new HealthReportData([CheckResultData::fromFindings('mixed', 'Mixed', $findings)], []));
    $package = ['package' => 'vendor/package', 'lockedVersion' => '1.0.0'];

    $valid = $render([
        $finding('composer-advisories', $package + ['affectedVersions' => '<1.0.1', 'fixedVersions' => ['1.0.1']]),
        $finding('composer-advisories', ['count' => 1]),
        $finding('npm-advisories', $package + ['fixedVersions' => []]),
        $finding('php-eol', ['cycle' => '8.1', 'securitySupportEndsOn' => '2025-12-31', 'activeSupportEndsOn' => null]),
        $finding('node-eol', ['cycle' => '20', 'securitySupportEndsOn' => null, 'activeSupportEndsOn' => '2026-04-01']),
        $finding('composer-abandoned', $package + ['replacement' => null]),
        $finding('composer-stale', $package),
        $finding('laravel-next-blocker', $package + ['targetMajor' => 13]),
        $finding('laravel-pending-migrations', ['count' => 2]),
        $finding('laravel-failed-jobs', ['count' => 3, 'oldestAgeHours' => null]),
        $finding('laravel-scheduler-heartbeat', ['minutesSinceLastRun' => 12]),
        $finding('laravel-debug-mode', []),
        $finding('laravel-database', ['connection' => 'mysql']),
        $finding('laravel-cache', ['store' => 'redis']),
        $finding('laravel-storage', ['directory' => 'bootstrap/cache']),
        $finding('laravel-disk-space', ['usedPercent' => 91]),
        $finding('laravel-queue-backlog', ['queue' => 'default', 'pendingCount' => 1200, 'oldestAgeMinutes' => null]),
        $finding('php-platform-requirements', ['requirement' => 'ext-intl', 'requiredBy' => ['laravel/framework'], 'checkedPhpVersion' => '8.4.1', 'isStandaloneCli' => true]),
        $finding('composer-outdated', $package + ['latestPatch' => '1.0.3', 'latestMinor' => '1.2.0', 'latestMajor' => null, 'isMajorBehind' => false]),
        $finding('composer-outdated', ['count' => 2]),
        $finding('laravel-queue-workers', ['connection' => 'redis', 'queue' => 'emails', 'minutesSinceLastSeen' => null]),
        $finding('laravel-queue-workers', ['status' => 'paused', 'processes' => 4, 'supervisors' => ['supervisor-1']]),
        $finding('npm-outdated', ['package' => 'lodash', 'lockedVersion' => '4.17.20', 'latestPatch' => '4.17.21', 'latestMinor' => '4.17.21', 'latestMajor' => null, 'isMajorBehind' => false]),
        $finding('npm-outdated', ['count' => 1]),
        $finding('laravel-backups', ['disk' => 's3', 'ageHours' => 30, 'sizeMb' => 12.5, 'count' => 7]),
        $finding('laravel-backups', ['disk' => 's3', 'ageHours' => null, 'sizeMb' => null, 'count' => null]),
        $finding('laravel-database-connections', ['connection' => 'mysql', 'connectionCount' => 120]),
        $finding('laravel-database-size', ['connection' => 'mysql', 'sizeMb' => 512.25, 'largestTables' => [['table' => 'activity_log', 'sizeMb' => 300.5], ['table' => 'users', 'sizeMb' => 2]]]),
        $finding('laravel-database-size', ['connection' => 'sqlite', 'sizeMb' => 0.02, 'largestTables' => []]),
        $finding('php-opcache', ['sapi' => 'web', 'ageMinutes' => 12, 'isOpcacheEnabled' => true, 'hitRate' => 82.5, 'memoryUsedPercent' => 40.0, 'cachedScripts' => 1500, 'isValidateTimestampsEnabled' => false]),
        $finding('php-opcache', ['sapi' => 'web', 'ageMinutes' => 0, 'isOpcacheEnabled' => false, 'hitRate' => null, 'memoryUsedPercent' => null, 'cachedScripts' => null, 'isValidateTimestampsEnabled' => true]),
        $finding('php-production-ini', ['directive' => 'display_errors', 'isEnabled' => true, 'sapi' => 'web', 'ageMinutes' => 3]),
    ]);
    $invalid = $render([
        $finding('php-eol', $package),
        $finding('laravel-failed-jobs', ['count' => 3, 'payload' => 'secret']),
        $finding('laravel-database', ['connection' => 'mysql', 'host' => 'db.internal']),
        $finding('laravel-storage', ['directory' => '/srv/app/storage']),
        $finding('laravel-queue-workers', ['connection' => 'redis', 'queue' => 'emails', 'minutesSinceLastSeen' => 3, 'host' => 'worker-1.internal']),
        $finding('laravel-queue-workers', ['status' => 'running', 'processes' => 4, 'supervisors' => []]),
    ]);

    $invalidOutdated = $render([
        $finding('composer-outdated', $package + ['latestPatch' => null, 'latestMinor' => null, 'latestMajor' => '2.0.0', 'isMajorBehind' => true, 'path' => '/srv/app']),
    ]);
    $invalidNpmOutdated = $render([
        $finding('npm-outdated', ['package' => 'lodash', 'lockedVersion' => '4.17.20', 'latestPatch' => null, 'latestMinor' => null, 'latestMajor' => '5.0.0', 'isMajorBehind' => true, 'lockfile' => '/srv/app/package-lock.json']),
    ]);

    $invalidBackups = $render([
        $finding('laravel-backups', ['disk' => 's3', 'ageHours' => 30, 'sizeMb' => 12.5, 'count' => 7, 'bucket' => 'backups-bucket']),
    ]);
    $invalidPhpRuntime = $render([
        $finding('php-production-ini', ['directive' => 'display_errors', 'isEnabled' => true, 'sapi' => 'web', 'ageMinutes' => 3, 'iniFile' => '/etc/php/8.4/cli/php.ini']),
        $finding('php-opcache', ['sapi' => 'fpm', 'ageMinutes' => null, 'isOpcacheEnabled' => false, 'hitRate' => null, 'memoryUsedPercent' => null, 'cachedScripts' => null, 'isValidateTimestampsEnabled' => true]),
        $finding('php-production-ini', ['directive' => 'expose_php', 'isEnabled' => true, 'sapi' => 'cli', 'ageMinutes' => null]),
    ]);

    expect(reportSchemaErrors($valid))->toBe([])
        ->and(reportSchemaErrors($invalid))->not->toBe([])
        ->and(reportSchemaErrors($invalidOutdated))->not->toBe([])
        ->and(reportSchemaErrors($invalidNpmOutdated))->not->toBe([])
        ->and(reportSchemaErrors($invalidBackups))->not->toBe([]);

    foreach ([
        $finding('laravel-database-connections', ['connection' => 'mysql', 'connectionCount' => 120, 'host' => 'db.internal']),
        $finding('laravel-database-size', ['connection' => 'sqlite', 'sizeMb' => 1.5, 'largestTables' => [], 'path' => '/srv/app/database.sqlite']),
        $finding('laravel-database-size', ['connection' => 'mysql', 'sizeMb' => 1.5, 'largestTables' => [['table' => 'users', 'sizeMb' => 1.5, 'schema' => 'production_db']]]),
    ] as $invalidDatabaseFinding) {
        expect(reportSchemaErrors($render([$invalidDatabaseFinding])))->not->toBe([]);
    }

    expect(reportSchemaErrors($invalidPhpRuntime))->not->toBe([]);
});

/**
 * Run `excubia check --format=json` on the healthy Laravel 13 fixture and
 * return the report. An empty `$responses` with `--offline` and an empty
 * cache directory leaves every end-of-life check skipped.
 *
 * @param  array<string, mixed>  $responses
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>
 */
function healthyJsonReport(array $responses, array $options = []): array
{
    $project = sys_get_temp_dir().'/excubia-healthy-'.bin2hex(random_bytes(4));
    mkdir($project);

    foreach (['composer.json', 'composer.lock', '.nvmrc'] as $file) {
        copy(dirname(__DIR__).'/Fixtures/Projects/healthy-laravel-13/'.$file, $project.'/'.$file);
    }

    file_put_contents($project.'/excubia.json', json_encode(['cache' => ['directory' => $project.'/cache']], JSON_THROW_ON_ERROR));
    $tester = new CommandTester(new CheckCommand(new FakeHttpClient($responses), new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC'))));
    $tester->execute([
        'path' => $project,
        '--format' => 'json',
        '--fail-on' => 'none',
        '--no-banner' => true,
        ...$options,
    ]);

    return json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, mixed>
 */
function supportedCycleResponses(): array
{
    return [
        'https://endoflife.date/api/php.json' => [['cycle' => '8.4', 'eol' => '2028-12-31', 'support' => '2026-12-31', 'lts' => false]],
        'https://endoflife.date/api/laravel.json' => [['cycle' => '13', 'eol' => '2028-03-17', 'support' => '2027-09-17', 'lts' => false]],
        'https://endoflife.date/api/nodejs.json' => [['cycle' => '22', 'eol' => '2028-04-30', 'support' => false, 'lts' => true]],
    ];
}

it('puts the support dates in project even when every end-of-life check passes', function () {
    $report = healthyJsonReport(supportedCycleResponses(), ['--only' => 'php-eol,laravel-eol,node-eol']);

    expect(array_column($report['checks'], 'status'))->toBe(['pass', 'pass', 'pass'])
        ->and($report['project'])->toBe([
            'php' => ['version' => '8.4.1', 'cycle' => '8.4', 'securitySupportEndsOn' => '2028-12-31', 'activeSupportEndsOn' => '2026-12-31'],
            'laravel' => ['version' => 'v13.39.0', 'cycle' => '13', 'securitySupportEndsOn' => '2028-03-17', 'activeSupportEndsOn' => '2027-09-17'],
            'node' => ['version' => '22', 'cycle' => '22', 'securitySupportEndsOn' => '2028-04-30', 'activeSupportEndsOn' => null],
        ])
        ->and(reportSchemaErrors($report))->toBe([]);
});

it('keeps the versions and nulls the dates offline with an empty cache', function () {
    $report = healthyJsonReport([], ['--offline' => true, '--only' => 'php-eol,laravel-eol,node-eol']);
    $empty = ['cycle' => null, 'securitySupportEndsOn' => null, 'activeSupportEndsOn' => null];

    expect(array_column($report['checks'], 'status'))->toBe(['skipped', 'skipped', 'skipped'])
        ->and($report['project'])->toBe([
            'php' => ['version' => '8.4.1'] + $empty,
            'laravel' => ['version' => 'v13.39.0'] + $empty,
            'node' => ['version' => '22'] + $empty,
        ])
        ->and(reportSchemaErrors($report))->toBe([]);
});

it('nulls the dates of a skipped check and keeps them through an ignore rule', function () {
    $report = healthyJsonReport(supportedCycleResponses(), ['--only' => 'laravel-eol,node-eol']);

    expect($report['project']['php'])->toBe(['version' => '8.4.1', 'cycle' => null, 'securitySupportEndsOn' => null, 'activeSupportEndsOn' => null])
        ->and($report['project']['laravel']['cycle'])->toBe('13');

    $cycle = new EndOfLifeCycleData('8.1', null, '2025-12-31', '2023-11-25', false);
    $finding = new FindingData('php-eol', SeverityEnum::Critical, FindingStatusEnum::Fail, 'php', 'Security support ended.', '', null);
    $configuration = new ExcubiaConfigurationData(null, true, 24, 180, 180, true, [['checkId' => 'php-eol', 'subject' => 'php', 'advisoryId' => null, 'reason' => 'Accepted.', 'until' => null]], null, 24);
    $filtered = (new HealthReportData([CheckResultData::fromFindings('php-eol', 'PHP end of life', [$finding], $cycle)], []))
        ->withoutIgnoredFindings($configuration, new DateTimeImmutable('2026-11-03'));

    expect($filtered->checkResults[0]->findings)->toBe([])
        ->and($filtered->checkResults[0]->endOfLifeCycle)->toBe($cycle);
});
