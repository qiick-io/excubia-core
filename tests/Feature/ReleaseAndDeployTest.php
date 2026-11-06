<?php

declare(strict_types=1);

use JsonSchema\Validator;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Console\PushCommand;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

const DEPLOY_ENDPOINT = 'https://excubia.example.test/api/reports';
const DEPLOY_COMMIT = '89abcdef0123456789abcdef0123456789abcdef';

/**
 * A project the scan answers without any request: no packages, no PHP
 * constraint. Extra files (excubia.json, .git/…) are merged in.
 *
 * @param  array<string, string>  $files
 */
function releaseProject(array $files = []): string
{
    return temporaryProject([
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [], "packages-dev": []}',
        ...$files,
    ]);
}

/**
 * @param  array<string, string>  $environment
 * @return array<string, mixed>
 */
function releaseJsonReport(string $project, array $environment = []): array
{
    $tester = new CommandTester(new CheckCommand(new FakeHttpClient([]), new DateTimeImmutable('2026-11-06', new DateTimeZone('UTC')), environment: $environment));
    $tester->execute(['path' => $project, '--format' => 'json', '--no-banner' => true, '--no-cache' => true]);

    return json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $report
 * @return list<string>
 */
function releaseReportSchemaErrors(array $report): array
{
    $validator = new Validator;
    $document = json_decode(json_encode($report, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $validator->validate($document, (object) ['$ref' => 'file://'.dirname(__DIR__, 2).'/docs/report-schema.json']);

    return array_map(fn (array $error): string => $error['property'].': '.$error['message'], $validator->getErrors());
}

/**
 * @param  array<string, string>  $environment
 * @param  array<string, mixed>  $input
 */
function deployPush(FakeHttpClient $client, string $project, array $environment, array $input = ['--deploy' => true]): CommandTester
{
    $tester = new CommandTester(new PushCommand($client, new DateTimeImmutable('2026-11-06 09:30:00', new DateTimeZone('UTC')), [
        'EXCUBIA_APP_URL' => 'https://excubia.example.test',
        'EXCUBIA_APP_TOKEN' => 'deploy-token',
        ...$environment,
    ]));
    $tester->execute(['path' => $project, ...$input]);

    return $tester;
}

it('adds the release from the environment to the JSON report, after excubiaVersion', function () {
    $report = releaseJsonReport(releaseProject(), ['GITHUB_SHA' => DEPLOY_COMMIT]);

    expect($report['release'])->toBe(DEPLOY_COMMIT)
        ->and(array_keys($report))->toBe(['schemaVersion', 'generatedAt', 'excubiaVersion', 'release', 'project', 'checks', 'upgradePlan'])
        ->and(releaseReportSchemaErrors($report))->toBe([]);
});

it('lets the release key of excubia.json win and omits an invalid release', function () {
    expect(releaseJsonReport(releaseProject(['excubia.json' => '{"release": "v2.0.0"}']), ['EXCUBIA_RELEASE' => 'v1.0.0'])['release'])->toBe('v2.0.0')
        ->and(releaseJsonReport(releaseProject(['excubia.json' => '{"release": "v2 with spaces"}']), ['EXCUBIA_RELEASE' => 'v1.0.0']))->not->toHaveKey('release')
        ->and(releaseJsonReport(releaseProject(), ['EXCUBIA_RELEASE' => str_repeat('x', 101)]))->not->toHaveKey('release');
});

it('omits the release without any source, including without a git checkout', function () {
    expect(releaseJsonReport(releaseProject()))->not->toHaveKey('release');
});

it('falls back to the commit in .git for the standalone CLI, without the branch name', function () {
    $project = releaseProject([
        '.git/HEAD' => "ref: refs/heads/customer-acme-secret\n",
        '.git/refs/heads/customer-acme-secret' => DEPLOY_COMMIT."\n",
    ]);
    $report = releaseJsonReport($project);

    expect($report['release'])->toBe(DEPLOY_COMMIT)
        ->and(json_encode($report, JSON_THROW_ON_ERROR))->not->toContain('customer-acme-secret');
});

it('loads the release key and rejects a value that is not a string', function () {
    $loader = new ExcubiaConfigurationLoader;

    expect($loader->load(null, temporaryProject(['excubia.json' => '{"release": "v1.0.0"}']))->release)->toBe('v1.0.0')
        ->and($loader->load(null, temporaryProject([]))->release)->toBeNull()
        ->and(fn () => $loader->load(null, temporaryProject(['excubia.json' => '{"release": 12}'])))->toThrow(InvalidConfigurationException::class, 'release must be a string.');
});

it('keeps the release, runtime, exceptions and privacy settings when CLI options override the config', function () {
    $project = releaseProject(['excubia.json' => json_encode([
        'release' => 'v3.0.0',
        'privacy' => ['skipPackages' => ['acme/*']],
        'runtime' => ['enabled' => true],
        'exceptions' => ['enabled' => true],
    ], JSON_THROW_ON_ERROR)]);
    $scan = (new CheckCommand(new FakeHttpClient([]), environment: []))->scan($project, ['no-dev' => true, 'fail-on' => 'none', 'strict' => true, 'no-cache' => true], new BufferedOutput);

    expect($scan['configuration']->isDevelopmentDependencyIncluded)->toBeFalse()
        ->and($scan['configuration']->skippedPackagePatterns)->toBe(['acme/*'])
        ->and($scan['configuration']->runtime->isEnabled)->toBeTrue()
        ->and($scan['configuration']->exceptions->isEnabled)->toBeTrue()
        ->and($scan['report']->release)->toBe('v3.0.0');
});

it('sends deployedAt and the release with push --deploy', function () {
    $client = new FakeHttpClient([DEPLOY_ENDPOINT => ['stored' => true]]);
    $tester = deployPush($client, releaseProject(), ['EXCUBIA_RELEASE' => 'v1.4.0']);
    $report = $client->postedPayloads[0]['report'];

    expect($tester->getStatusCode())->toBe(0)
        ->and($report['release'])->toBe('v1.4.0')
        ->and($report['deployedAt'])->toBe('2026-11-06T09:30:00Z')
        ->and(array_keys($report))->toBe(['schemaVersion', 'generatedAt', 'excubiaVersion', 'release', 'deployedAt', 'project', 'checks', 'upgradePlan'])
        ->and(releaseReportSchemaErrors($report))->toBe([])
        ->and($tester->getDisplay())->toContain('Deploy marker and report sent to excubia-app (batch ')
        ->and($tester->getDisplay())->not->toContain('No release identifier');
});

it('sends no deployedAt without --deploy', function () {
    $client = new FakeHttpClient([DEPLOY_ENDPOINT => ['stored' => true]]);
    $tester = deployPush($client, releaseProject(), ['EXCUBIA_RELEASE' => 'v1.4.0'], []);

    expect($client->postedPayloads[0]['report'])->not->toHaveKey('deployedAt')
        ->and($client->postedPayloads[0]['report']['release'])->toBe('v1.4.0')
        ->and($tester->getDisplay())->toContain('Report sent to excubia-app');
});

it('still sends the deploy marker without a release, with a warning', function () {
    $client = new FakeHttpClient([DEPLOY_ENDPOINT => ['stored' => true]]);
    $tester = deployPush($client, releaseProject(), []);
    $report = $client->postedPayloads[0]['report'];

    expect($tester->getStatusCode())->toBe(0)
        ->and($report)->not->toHaveKey('release')
        ->and($report['deployedAt'])->toBe('2026-11-06T09:30:00Z')
        ->and($tester->getDisplay())->toContain('No release identifier was found');
});
