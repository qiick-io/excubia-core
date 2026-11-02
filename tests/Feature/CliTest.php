<?php

declare(strict_types=1);

use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Console\ClearCacheCommand;
use Qiick\Excubia\Laravel\ExcubiaCheckCommand;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Tester\CommandTester;

function cliCheck(FakeHttpClient $client): CommandTester
{
    return new CommandTester(new CheckCommand($client, new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC'))));
}

function outdatedProject(): string
{
    return dirname(__DIR__).'/Fixtures/Projects/outdated-laravel-10';
}

it('fails a critical php end-of-life finding and can hide the banner', function () {
    $tester = cliCheck(new FakeHttpClient([
        'https://endoflife.date/api/php.json' => [
            ['cycle' => '8.1', 'eol' => '2025-12-31', 'support' => '2023-11-25', 'lts' => false],
            ['cycle' => '8.4', 'eol' => '2028-12-31', 'support' => '2026-12-31', 'lts' => false],
        ],
    ]));
    $exitCode = $tester->execute([
        'path' => outdatedProject(),
        '--only' => 'php-eol',
        '--no-banner' => true,
        '--no-cache' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and($tester->getDisplay())->toContain('Security support ended')
        ->and($tester->getDisplay())->toContain('Upgrade PHP to 8.4')
        ->and($tester->getDisplay())->not->toContain('Health checks for Laravel apps.');
});

it('returns zero when fail-on is none and writes json to a file', function () {
    $output = sys_get_temp_dir().'/excubia-report-'.bin2hex(random_bytes(4)).'.json';
    $tester = cliCheck(new FakeHttpClient([
        'https://endoflife.date/api/php.json' => [
            ['cycle' => '8.1', 'eol' => '2025-12-31', 'support' => '2023-11-25', 'lts' => false],
        ],
    ]));
    $exitCode = $tester->execute([
        'path' => outdatedProject(),
        '--only' => 'php-eol',
        '--fail-on' => 'none',
        '--format' => 'json',
        '--output' => $output,
        '--no-banner' => true,
        '--no-cache' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and($tester->getDisplay())->toBe('')
        ->and(file_get_contents($output))->toContain('"schemaVersion": 1');
});

it('exits 2 for a missing lock, an unknown check, and a bad format', function () {
    $empty = sys_get_temp_dir().'/excubia-empty-'.bin2hex(random_bytes(4));
    mkdir($empty);
    $tester = cliCheck(new FakeHttpClient([]));

    expect($tester->execute(['path' => $empty, '--no-banner' => true], ['capture_stderr_separately' => true]))->toBe(2)
        ->and($tester->getErrorOutput())->toContain('composer.lock');

    expect($tester->execute([
        'path' => outdatedProject(),
        '--only' => 'not-a-check',
        '--no-banner' => true,
    ]))->toBe(2);

    expect($tester->execute([
        'path' => outdatedProject(),
        '--format' => 'sarif',
        '--no-banner' => true,
        '--only' => 'php-eol',
        '--no-cache' => true,
    ]))->toBe(2);
});

it('skips when offline and clears the cache directory from config', function () {
    $cache = sys_get_temp_dir().'/excubia-cache-'.bin2hex(random_bytes(4));
    mkdir($cache);
    file_put_contents($cache.'/cached.json', '{}');
    $project = sys_get_temp_dir().'/excubia-project-'.bin2hex(random_bytes(4));
    mkdir($project);
    copy(outdatedProject().'/composer.json', $project.'/composer.json');
    copy(outdatedProject().'/composer.lock', $project.'/composer.lock');
    file_put_contents($project.'/excubia.json', json_encode(['cache' => ['directory' => $cache]], JSON_THROW_ON_ERROR));

    $check = cliCheck(new FakeHttpClient([]));

    expect($check->execute([
        'path' => $project,
        '--only' => 'php-eol',
        '--offline' => true,
        '--format' => 'json',
        '--no-banner' => true,
    ]))->toBe(0)
        ->and($check->getDisplay())->toContain('skipped');

    $clear = new CommandTester(new ClearCacheCommand);
    expect($clear->execute(['path' => $project]))->toBe(0)
        ->and(glob($cache.'/*.json'))->toBe([]);
});

it('names the artisan alias and prints the banner and version', function () {
    expect((new ExcubiaCheckCommand)->getName())->toBe('excubia:check');

    $tester = cliCheck(new FakeHttpClient([
        'https://endoflife.date/api/php.json' => [
            ['cycle' => '8.4', 'eol' => '2028-12-31', 'support' => '2026-12-31', 'lts' => false],
        ],
    ]));
    $tester->execute([
        'path' => dirname(__DIR__).'/Fixtures/Projects/healthy-laravel-13',
        '--only' => 'php-eol',
        '--no-cache' => true,
        '--fail-on' => 'none',
    ]);

    expect($tester->getDisplay())->toContain('Excubia');

    $binary = dirname(__DIR__, 2).'/bin/excubia';
    exec('php '.escapeshellarg($binary).' --version 2>&1', $output, $exitCode);

    expect($exitCode)->toBe(0)
        ->and(implode("\n", $output))->toContain('Excubia');
});
