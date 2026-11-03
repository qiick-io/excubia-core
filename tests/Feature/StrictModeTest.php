<?php

declare(strict_types=1);

use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Laravel\ExcubiaCheckCommand;
use Qiick\Excubia\Laravel\ExcubiaPushCommand;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A copy of the outdated Laravel 10 fixture with an empty cache directory
 * of its own, so no real cached response can leak into the run.
 *
 * @param  array<string, mixed>  $configuration
 */
function strictProject(array $configuration = [], string $fixture = 'outdated-laravel-10'): string
{
    $source = dirname(__DIR__).'/Fixtures/Projects/'.$fixture;
    $configuration['cache'] = ['directory' => temporaryProject([])];

    return temporaryProject([
        'composer.json' => (string) file_get_contents($source.'/composer.json'),
        'composer.lock' => (string) file_get_contents($source.'/composer.lock'),
        'excubia.json' => json_encode($configuration, JSON_THROW_ON_ERROR),
    ]);
}

/**
 * endoflife.date answers nothing: every request is a faked network failure.
 */
function strictTester(array $responses = []): CommandTester
{
    return new CommandTester(new CheckCommand(new FakeHttpClient($responses), new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC'))));
}

/**
 * @return list<array<string, mixed>>
 */
function reportFindings(string $json): array
{
    $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $findings = [];

    foreach ($report['checks'] as $check) {
        foreach ($check['findings'] as $finding) {
            $findings[] = $finding;
        }
    }

    return $findings;
}

it('exits 3 with --strict when a data source fails and nothing is cached, naming the source', function () {
    $tester = strictTester();
    $exitCode = $tester->execute([
        'path' => strictProject(),
        '--only' => 'php-eol',
        '--strict' => true,
        '--format' => 'json',
        '--no-banner' => true,
    ], ['capture_stderr_separately' => true]);

    expect($exitCode)->toBe(3)
        ->and($tester->getErrorOutput())->toContain('--strict')
        ->and($tester->getErrorOutput())->toContain('php-eol (php): endoflife.date did not respond.')
        ->and(reportFindings($tester->getDisplay())[0])->toMatchArray([
            'status' => 'skipped',
            'skipReason' => 'data-source-failure',
        ]);
});

it('keeps exit 0 and the skipped warning row without --strict, as before', function () {
    $tester = strictTester();
    $exitCode = $tester->execute([
        'path' => strictProject(),
        '--only' => 'php-eol',
        '--format' => 'json',
        '--no-banner' => true,
    ], ['capture_stderr_separately' => true]);

    expect($exitCode)->toBe(0)
        ->and(reportFindings($tester->getDisplay())[0])->toMatchArray([
            'status' => 'skipped',
            'message' => 'endoflife.date did not respond.',
            'skipReason' => 'data-source-failure',
        ])
        ->and($tester->getErrorOutput())->toBe('');
});

it('exits 0 with --strict --offline and an empty cache, because those skips are deliberate', function () {
    $client = new FakeHttpClient([]);
    $tester = new CommandTester(new CheckCommand($client, new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC'))));
    $exitCode = $tester->execute([
        'path' => strictProject(),
        '--strict' => true,
        '--offline' => true,
        '--format' => 'json',
        '--no-banner' => true,
    ], ['capture_stderr_separately' => true]);
    $skipReasons = array_unique(array_column(reportFindings($tester->getDisplay()), 'skipReason'));

    expect($exitCode)->toBe(0)
        ->and($client->calls)->toBe(0)
        ->and($skipReasons)->toContain('offline')
        ->and($skipReasons)->not->toContain('data-source-failure')
        ->and($tester->getErrorOutput())->toBe('');
});

it('does not exit 3 for laravel checks on a non-laravel project or a skip removed by an ignore rule', function () {
    $tester = strictTester();

    expect($tester->execute([
        'path' => strictProject([], 'non-laravel'),
        '--only' => 'laravel-eol,laravel-next-blocker',
        '--strict' => true,
        '--format' => 'json',
        '--no-banner' => true,
    ]))->toBe(0)
        ->and(array_column(reportFindings($tester->getDisplay()), 'skipReason'))->toBe(['not-applicable', 'not-applicable']);

    $ignored = strictProject(['ignore' => [['checkId' => 'php-eol', 'subject' => 'php', 'reason' => 'Known outage']]]);

    expect($tester->execute(['path' => $ignored, '--only' => 'php-eol', '--strict' => true, '--no-banner' => true]))->toBe(0);
});

it('reads strict from excubia.json, and --strict cannot be turned off by the file', function () {
    $tester = strictTester();

    expect($tester->execute(['path' => strictProject(['strict' => true]), '--only' => 'php-eol', '--no-banner' => true]))->toBe(3)
        ->and($tester->execute(['path' => strictProject(['strict' => false]), '--only' => 'php-eol', '--strict' => true, '--no-banner' => true]))->toBe(3)
        ->and($tester->execute(['path' => strictProject(['strict' => false]), '--only' => 'php-eol', '--no-banner' => true]))->toBe(0)
        ->and($tester->execute(['path' => strictProject(['strict' => 'yes']), '--only' => 'php-eol', '--no-banner' => true]))->toBe(2);
});

it('lets exit 2 win over 3, and exit 1 win over 3', function () {
    $tester = strictTester([
        'https://endoflife.date/api/php.json' => [
            ['cycle' => '8.1', 'eol' => '2025-12-31', 'support' => '2023-11-25', 'lts' => false],
        ],
    ]);

    expect($tester->execute(['path' => strictProject(), '--only' => 'php-eol,laravel-eol', '--strict' => true, '--format' => 'xml', '--no-banner' => true]))->toBe(2)
        ->and($tester->execute(['path' => dirname(__DIR__).'/Fixtures/Projects/broken-lockfile', '--strict' => true, '--no-banner' => true]))->toBe(2);

    $exitCode = $tester->execute([
        'path' => strictProject(),
        '--only' => 'php-eol,laravel-eol',
        '--strict' => true,
        '--no-banner' => true,
    ], ['capture_stderr_separately' => true]);

    expect($exitCode)->toBe(1)
        ->and($tester->getDisplay())->toContain('Security support ended')
        ->and($tester->getErrorOutput())->not->toContain('--strict');

    expect($tester->execute([
        'path' => strictProject(),
        '--only' => 'php-eol,laravel-eol',
        '--strict' => true,
        '--fail-on' => 'none',
        '--no-banner' => true,
    ]))->toBe(3);
});

it('accepts --strict on both artisan aliases', function () {
    expect((new ExcubiaCheckCommand)->getDefinition()->hasOption('strict'))->toBeTrue()
        ->and((new ExcubiaPushCommand)->getDefinition()->hasOption('strict'))->toBeTrue();
});
