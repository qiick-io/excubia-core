<?php

declare(strict_types=1);

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Parsers\ComposerLockParser;
use Qiick\Excubia\Parsers\PackageLockParser;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `excubia check --only=dependency-licenses` on the licenses fixture, which
 * covers allowed, denied, copyleft, unknown, missing, `OR` / `AND`, dev and
 * private packages. The fake client has no answers: the check must not ask.
 *
 * @param  array<string, mixed>  $options
 * @return array{exitCode: int, display: string}
 */
function licensesCheck(array $options = [], ?string $project = null): array
{
    $client = new FakeHttpClient([]);
    $tester = new CommandTester(new CheckCommand($client));
    $exitCode = $tester->execute([
        'path' => $project ?? dirname(__DIR__).'/Fixtures/Projects/licenses',
        '--only' => 'dependency-licenses',
        '--no-banner' => true,
        '--no-cache' => true,
        ...$options,
    ]);

    expect($client->calls)->toBe(0);

    return ['exitCode' => $exitCode, 'display' => $tester->getDisplay()];
}

/**
 * @return array<string, mixed>
 */
function licensesJsonReport(array $options = [], ?string $project = null): array
{
    return json_decode(licensesCheck(['--format' => 'json'] + $options, $project)['display'], true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The fixture rows as `subject => [status, severity]`.
 *
 * @param  array<string, mixed>  $report
 * @return array<string, array{string, string}>
 */
function licenseRows(array $report): array
{
    $rows = [];

    foreach ($report['checks'][0]['findings'] as $finding) {
        $rows[$finding['subject']] = [$finding['status'], $finding['severity']];
    }

    return $rows;
}

it('reports denied, copyleft, missing and unknown licenses of both lockfiles', function () {
    $report = licensesJsonReport();

    expect($report['checks'][0]['checkId'])->toBe('dependency-licenses')
        ->and($report['checks'][0]['status'])->toBe('fail')
        ->and(licenseRows($report))->toBe([
            'acme/both' => ['warn', 'medium'],
            'acme/copyleft' => ['warn', 'medium'],
            'acme/denied' => ['fail', 'high'],
            'acme/missing' => ['warn', 'low'],
            'acme/unknown' => ['warn', 'low'],
            'acme/in-house' => ['warn', 'low'],
            'gpl-lib' => ['warn', 'medium'],
            'see-file' => ['warn', 'low'],
        ]);
});

it('emits the documented details and a schema-valid report', function () {
    $report = licensesJsonReport();
    $findings = array_column($report['checks'][0]['findings'], null, 'subject');

    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/docs/report-schema.json'), false, 512, JSON_THROW_ON_ERROR);
    $schema->{'$schema'} = 'http://json-schema.org/draft-07/schema#';
    $validator = new Validator;
    $document = json_decode(json_encode($report, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $validator->validate($document, $schema, Constraint::CHECK_MODE_STRICT);

    expect($validator->getErrors())->toBe([])
        ->and($findings['acme/denied']['details'])->toBe(['package' => 'acme/denied', 'lockedVersion' => '3.0.0', 'licenses' => ['SSPL-1.0'], 'ecosystem' => 'composer', 'kind' => 'denied'])
        ->and($findings['acme/both']['details']['licenses'])->toBe(['(MIT and GPL-3.0-only)'])
        ->and($findings['gpl-lib']['details'])->toBe(['package' => 'gpl-lib', 'lockedVersion' => '1.0.0', 'licenses' => ['GPL-3.0-or-later'], 'ecosystem' => 'npm', 'kind' => 'copyleft'])
        ->and($findings['acme/in-house']['evidenceUrl'])->toBeNull()
        ->and($findings['gpl-lib']['evidenceUrl'])->toBe('https://www.npmjs.com/package/gpl-lib');
});

it('adds the license inventory per license id and ecosystem at the top level', function () {
    $licenses = licensesJsonReport()['licenses'];

    expect($licenses['packageCount'])->toBe(14)
        ->and($licenses['byEcosystem']['composer'])->toBe([
            'packageCount' => 9,
            'byLicense' => [
                ['license' => 'AGPL-3.0-or-later', 'packageCount' => 1],
                ['license' => 'Acme-Custom-1.0', 'packageCount' => 1],
                ['license' => 'GPL-2.0+', 'packageCount' => 1],
                ['license' => 'GPL-3.0-only', 'packageCount' => 2],
                ['license' => 'MIT', 'packageCount' => 3],
                ['license' => 'NOASSERTION', 'packageCount' => 1],
                ['license' => 'SSPL-1.0', 'packageCount' => 1],
                ['license' => 'proprietary', 'packageCount' => 1],
            ],
        ])
        ->and($licenses['byEcosystem']['npm'])->toBe([
            'packageCount' => 5,
            'byLicense' => [
                ['license' => 'Apache-2.0', 'packageCount' => 1],
                ['license' => 'BSD-3-Clause', 'packageCount' => 1],
                ['license' => 'GPL-3.0-or-later', 'packageCount' => 1],
                ['license' => 'MIT', 'packageCount' => 1],
                ['license' => 'SEE LICENSE IN LICENSE.md', 'packageCount' => 1],
                ['license' => 'WTFPL', 'packageCount' => 1],
            ],
        ])
        ->and(array_column($licenses['byLicense'], 'packageCount', 'license')['MIT'])->toBe(4);
});

it('includes development dependencies with includeDev', function () {
    $fixture = dirname(__DIR__).'/Fixtures/Projects/licenses';
    $project = temporaryProject([
        'composer.json' => (string) file_get_contents($fixture.'/composer.json'),
        'composer.lock' => (string) file_get_contents($fixture.'/composer.lock'),
        'package-lock.json' => (string) file_get_contents($fixture.'/package-lock.json'),
        'excubia.json' => '{"checks": {"dependency-licenses": {"includeDev": true}}}',
    ]);

    $rows = licenseRows(licensesJsonReport(project: $project));
    $withoutComposerDev = licenseRows(licensesJsonReport(['--no-dev' => true], $project));

    expect($rows)->toHaveKey('acme/dev-tool')
        ->and($rows)->toHaveKey('dev-only')
        ->and($withoutComposerDev)->not->toHaveKey('acme/dev-tool')
        ->and($withoutComposerDev)->toHaveKey('dev-only');
});

it('never reports a license string that holds a path', function (string $format) {
    $display = licensesCheck(['--format' => $format])['display'];

    expect($display)->toContain('acme/unknown')
        ->and($display)->not->toContain('/home/someone')
        ->and($display)->not->toContain('home/someone');
})->with(['json', 'sarif', 'markdown', 'table']);

it('lets an ignore rule accept one package and keeps the inventory', function () {
    $fixture = dirname(__DIR__).'/Fixtures/Projects/licenses';
    $project = temporaryProject([
        'composer.json' => (string) file_get_contents($fixture.'/composer.json'),
        'composer.lock' => (string) file_get_contents($fixture.'/composer.lock'),
        'excubia.json' => '{"checks": {"dependency-licenses": {"denied": ["SSPL-*"]}}, "ignore": [{"checkId": "dependency-licenses", "subject": "acme/denied", "reason": "Internal tool only"}]}',
    ]);

    $report = licensesJsonReport(project: $project);

    expect(licenseRows($report))->not->toHaveKey('acme/denied')
        ->and($report['licenses']['byEcosystem']['composer']['packageCount'])->toBe(9)
        ->and($report['licenses']['byEcosystem']['npm'])->toBe(['packageCount' => 0, 'byLicense' => []]);
});

it('omits the inventory when the check does not run', function () {
    $project = temporaryProject([
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [{"name": "a/b", "version": "1.0.0", "license": ["GPL-3.0-only"]}], "packages-dev": []}',
    ]);
    $report = licensesJsonReport(['--only' => 'php-eol'], $project);

    expect($report)->not->toHaveKey('licenses')
        ->and(array_column($report['checks'], 'checkId'))->toBe(['php-eol']);
});

it('exits 1 on the denied license and 0 when only warnings are below --fail-on', function () {
    expect(licensesCheck()['exitCode'])->toBe(1)
        ->and(licensesCheck(['--fail-on' => 'critical'])['exitCode'])->toBe(0)
        ->and(licensesCheck(['--fail-on' => 'medium'])['exitCode'])->toBe(1);
});

it('points SARIF results at the lockfile of the package', function () {
    $sarif = json_decode(licensesCheck(['--format' => 'sarif'])['display'], true, 512, JSON_THROW_ON_ERROR);
    $locations = [];

    foreach ($sarif['runs'][0]['results'] as $result) {
        $locations[$result['properties']['subject']] = $result['locations'][0]['physicalLocation']['artifactLocation']['uri'];
    }

    expect($locations['acme/denied'])->toBe('composer.lock')
        ->and($locations['gpl-lib'])->toBe('package-lock.json')
        ->and($sarif['runs'][0]['tool']['driver']['rules'][0]['id'])->toBe('dependency-licenses');
});

it('reads the license entries of both lockfiles', function () {
    $composer = (new ComposerLockParser)->parse(['packages' => [
        ['name' => 'a/b', 'version' => '1.0.0', 'license' => ['MIT', 'GPL-2.0-or-later']],
        ['name' => 'a/c', 'version' => '1.0.0', 'license' => 'BSD-3-Clause'],
        ['name' => 'a/d', 'version' => '1.0.0', 'license' => ['C:\\Users\\me\\LICENSE', '', 'MIT', 'MIT', 42]],
        ['name' => 'a/e', 'version' => '1.0.0'],
    ]], [])['packages'];
    $npm = (new PackageLockParser)->parse(['lockfileVersion' => 3, 'packages' => [
        'node_modules/x' => ['version' => '1.0.0', 'license' => '(MIT OR Apache-2.0)'],
        'node_modules/y' => ['version' => '1.0.0', 'licenses' => [['type' => 'MIT'], ['type' => 'Apache-2.0']]],
        'node_modules/z' => ['version' => '1.0.0', 'license' => ['type' => 'ISC', 'url' => 'https://example.com/license']],
        'node_modules/w' => ['version' => '1.0.0', 'license' => 'https://example.com/license'],
    ]], []);

    expect(array_map(fn ($package) => $package->licenses, $composer))->toBe([['MIT', 'GPL-2.0-or-later'], ['BSD-3-Clause'], ['MIT'], []])
        ->and(array_map(fn ($package) => $package->licenses, $npm))->toBe([['(MIT OR Apache-2.0)'], ['MIT', 'Apache-2.0'], ['ISC'], []]);
});

it('loads checks.dependency-licenses and defaults includeDev to false', function () {
    $loader = new ExcubiaConfigurationLoader;
    $defaults = $loader->load(null, temporaryProject([]))->dependencyLicenses;
    $loaded = $loader->load(null, temporaryProject([
        'excubia.json' => '{"checks": {"dependency-licenses": {"allowed": ["MIT", "LGPL-*"], "denied": ["AGPL-3.0-only"], "includeDev": true}}}',
    ]))->dependencyLicenses;

    expect($defaults->allowedLicenses)->toBe([])
        ->and($defaults->deniedLicenses)->toBe([])
        ->and($defaults->isDevelopmentDependencyIncluded)->toBeFalse()
        ->and($loaded->allowedLicenses)->toBe(['MIT', 'LGPL-*'])
        ->and($loaded->deniedLicenses)->toBe(['AGPL-3.0-only'])
        ->and($loaded->isDevelopmentDependencyIncluded)->toBeTrue();
});

it('rejects invalid checks.dependency-licenses settings', function (string $document, string $message) {
    expect(fn () => (new ExcubiaConfigurationLoader)->load(null, temporaryProject(['excubia.json' => $document])))
        ->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'not an object' => ['{"checks": {"dependency-licenses": true}}', 'checks.dependency-licenses must be an object.'],
    'unknown key' => ['{"checks": {"dependency-licenses": {"enabled": false}}}', 'Unknown checks.dependency-licenses key [enabled].'],
    'allowed not a list' => ['{"checks": {"dependency-licenses": {"allowed": "MIT"}}}', 'checks.dependency-licenses.allowed must be a list of strings.'],
    'denied with an empty id' => ['{"checks": {"dependency-licenses": {"denied": [""]}}}', 'checks.dependency-licenses.denied[0] must be a non-empty string.'],
    'glob with a slash' => ['{"checks": {"dependency-licenses": {"allowed": ["MIT/X"]}}}', 'checks.dependency-licenses.allowed[0] must be an SPDX license id or glob'],
    'includeDev not a boolean' => ['{"checks": {"dependency-licenses": {"includeDev": "yes"}}}', 'checks.dependency-licenses.includeDev must be a boolean.'],
]);

it('keeps the license settings when command-line options override the file', function (array $options) {
    $rows = licenseRows(licensesJsonReport($options));

    expect($rows['acme/denied'])->toBe(['fail', 'high'])
        ->and($rows)->not->toHaveKey('acme/allowed');
})->with([
    'strict' => [['--strict' => true]],
    'fail-on' => [['--fail-on' => 'low']],
    'no-dev' => [['--no-dev' => true]],
]);
