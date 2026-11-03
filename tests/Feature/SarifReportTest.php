<?php

declare(strict_types=1);

use JsonSchema\Validator;
use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\HealthReportData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Renderers\SarifRenderer;
use Qiick\Excubia\Services\ProjectSnapshotService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Recorded data-source answers for the two fixtures. URLs without an entry
 * make the fake client fail, so those checks are skipped and emit no result.
 */
function sarifHttpClient(): FakeHttpClient
{
    return new FakeHttpClient([
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
                'laravel/framework' => [
                    [
                        'advisoryId' => 'PKSA-2y3q-xh2b-7vqk',
                        'packageName' => 'laravel/framework',
                        'affectedVersions' => '>=10.0.0,<10.48.23',
                        'link' => 'https://github.com/advisories/GHSA-gv7v-rgg6-548h',
                        'severity' => 'medium',
                    ],
                ],
            ],
        ],
    ]);
}

/**
 * Run `excubia check --format=sarif --output=report.sarif` on a fixture.
 *
 * @return array{exitCode: int, display: string, sarif: string}
 */
function sarifCheck(string $fixture, array $extraOptions = []): array
{
    $outputFile = sys_get_temp_dir().'/excubia-sarif-'.bin2hex(random_bytes(4)).'/report.sarif';
    mkdir(dirname($outputFile));
    $tester = new CommandTester(new CheckCommand(sarifHttpClient(), new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC'))));
    $exitCode = $tester->execute([
        'path' => dirname(__DIR__).'/Fixtures/Projects/'.$fixture,
        '--format' => 'sarif',
        '--output' => $outputFile,
        '--no-banner' => true,
        '--no-cache' => true,
        ...$extraOptions,
    ]);

    return ['exitCode' => $exitCode, 'display' => $tester->getDisplay(), 'sarif' => (string) file_get_contents($outputFile)];
}

/**
 * Validate a SARIF document against the vendored official 2.1.0 schema.
 *
 * @return list<string>
 */
function sarifSchemaErrors(string $sarif): array
{
    $validator = new Validator;
    $document = json_decode($sarif, false, 512, JSON_THROW_ON_ERROR);
    $validator->validate($document, (object) ['$ref' => 'file://'.dirname(__DIR__).'/Fixtures/Schemas/sarif-schema-2.1.0.json']);
    $errors = [];

    foreach ($validator->getErrors() as $error) {
        $errors[] = $error['property'].': '.$error['message'];
    }

    return $errors;
}

/**
 * @return array<string, mixed>
 */
function decodedSarif(string $sarif): array
{
    $decoded = json_decode($sarif, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException('SARIF output is not a JSON object.');
    }

    return $decoded;
}

it('writes schema-valid SARIF 2.1.0 for both fixtures to --output', function (string $fixture) {
    $run = sarifCheck($fixture);

    expect($run['display'])->toBe('')
        ->and(decodedSarif($run['sarif'])['version'])->toBe('2.1.0')
        ->and(sarifSchemaErrors($run['sarif']))->toBe([]);
})->with(['outdated-laravel-10', 'healthy-laravel-13']);

it('matches the SARIF snapshots of the outdated and healthy fixtures', function (string $fixture) {
    expect(sarifCheck($fixture)['sarif'])
        ->toBe(file_get_contents(dirname(__DIR__).'/Fixtures/Reports/'.$fixture.'.sarif'));
})->with(['outdated-laravel-10', 'healthy-laravel-13']);

it('keeps the exit codes and --fail-on of the other formats', function () {
    expect(sarifCheck('outdated-laravel-10')['exitCode'])->toBe(1)
        ->and(sarifCheck('outdated-laravel-10', ['--fail-on' => 'none'])['exitCode'])->toBe(0)
        ->and(sarifCheck('healthy-laravel-13')['exitCode'])->toBe(0);
});

it('emits no absolute paths or hostnames', function () {
    $sarif = sarifCheck('outdated-laravel-10')['sarif'];
    $hostname = gethostname();

    expect($sarif)->not->toContain(dirname(__DIR__, 2))
        ->and($sarif)->not->toContain(sys_get_temp_dir())
        ->and($hostname === false || ! str_contains($sarif, '"'.$hostname))->toBeTrue();

    foreach (decodedSarif($sarif)['runs'][0]['results'] as $result) {
        $uri = $result['locations'][0]['physicalLocation']['artifactLocation']['uri'];

        expect($uri)->not->toStartWith('/')
            ->and($uri)->not->toContain(':')
            ->and($uri)->not->toContain('..');
    }
});

it('produces identical fingerprints across two runs', function () {
    $fingerprints = function (): array {
        $values = [];

        foreach (decodedSarif(sarifCheck('outdated-laravel-10')['sarif'])['runs'][0]['results'] as $result) {
            $values[] = $result['partialFingerprints'];
        }

        return $values;
    };

    $first = $fingerprints();

    expect($first)->not->toBe([])
        ->and($fingerprints())->toBe($first);
});

it('maps severities, keeps the excubia severity, and drops pass and skipped rows', function () {
    $finding = fn (SeverityEnum $severity, FindingStatusEnum $status, string $subject): FindingData => new FindingData(
        'composer-advisories', $severity, $status, $subject, 'Known vulnerability.', 'Update '.$subject.'.', null, 'GHSA-'.$subject,
    );
    $report = new HealthReportData([
        CheckResultData::fromFindings('composer-advisories', 'Known vulnerabilities (Composer)', [
            $finding(SeverityEnum::Critical, FindingStatusEnum::Fail, 'a/critical'),
            $finding(SeverityEnum::High, FindingStatusEnum::Fail, 'a/high'),
            $finding(SeverityEnum::Medium, FindingStatusEnum::Warn, 'a/medium'),
            $finding(SeverityEnum::Low, FindingStatusEnum::Warn, 'a/low'),
            $finding(SeverityEnum::Info, FindingStatusEnum::Warn, 'a/info'),
            $finding(SeverityEnum::High, FindingStatusEnum::Pass, 'a/pass'),
            $finding(SeverityEnum::High, FindingStatusEnum::Skipped, 'a/skipped'),
        ]),
        CheckResultData::fromFindings('node-eol', 'Node end of life', [
            new FindingData('node-eol', SeverityEnum::High, FindingStatusEnum::Fail, 'node', 'Ended.', 'Upgrade Node.', null),
        ]),
    ], []);

    $sarif = (new SarifRenderer('.nvmrc'))->render($report);
    $run = decodedSarif($sarif)['runs'][0];
    $levels = [];

    foreach ($run['results'] as $result) {
        $levels[$result['properties']['subject']] = [$result['level'], $result['properties']['severity']];
    }

    expect(sarifSchemaErrors($sarif))->toBe([])
        ->and($run['tool']['driver']['rules'])->toHaveCount(2)
        ->and($run['tool']['driver']['rules'][0]['id'])->toBe('composer-advisories')
        ->and($levels)->toBe([
            'a/critical' => ['error', 'critical'],
            'a/high' => ['error', 'high'],
            'a/medium' => ['warning', 'medium'],
            'a/low' => ['note', 'low'],
            'a/info' => ['note', 'info'],
            'node' => ['error', 'high'],
        ])
        ->and($run['results'][0]['locations'][0]['physicalLocation']['artifactLocation']['uri'])->toBe('composer.lock')
        ->and($run['results'][5]['locations'][0]['physicalLocation']['artifactLocation']['uri'])->toBe('.nvmrc')
        ->and($run['results'][5]['ruleIndex'])->toBe(1);
});

it('names the file that declares the node version', function () {
    $project = sys_get_temp_dir().'/excubia-node-manifest-'.bin2hex(random_bytes(4));
    mkdir($project);
    $service = new ProjectSnapshotService;

    expect($service->nodeVersionManifest($project))->toBe('package.json');

    file_put_contents($project.'/.node-version', "22\n");
    expect($service->nodeVersionManifest($project))->toBe('.node-version');

    file_put_contents($project.'/.nvmrc', "\n");
    expect($service->nodeVersionManifest($project))->toBe('.node-version');

    file_put_contents($project.'/.nvmrc', "20\n");
    expect($service->nodeVersionManifest($project))->toBe('.nvmrc');
});
