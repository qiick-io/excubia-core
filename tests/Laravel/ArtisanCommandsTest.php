<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Copy of the outdated Laravel 10 fixture whose cache already holds the
 * PHP end-of-life response, so `excubia:check --offline` needs no network.
 */
function cachedOutdatedProject(): string
{
    $fixture = dirname(__DIR__).'/Fixtures/Projects/outdated-laravel-10';
    $project = temporaryProject([
        'composer.json' => (string) file_get_contents($fixture.'/composer.json'),
        'composer.lock' => (string) file_get_contents($fixture.'/composer.lock'),
        'excubia.json' => json_encode(['cache' => ['directory' => temporaryProject([])]], JSON_THROW_ON_ERROR),
    ]);
    $seed = new CommandTester(new CheckCommand(new FakeHttpClient([
        'https://endoflife.date/api/php.json' => [
            ['cycle' => '8.1', 'eol' => '2025-12-31', 'support' => '2023-11-25', 'lts' => false],
            ['cycle' => '8.4', 'eol' => '2028-12-31', 'support' => '2026-12-31', 'lts' => false],
        ],
    ])));
    $seed->execute(['path' => $project, '--only' => 'php-eol', '--fail-on' => 'none', '--no-banner' => true]);

    return $project;
}

it('registers excubia:check and excubia:push', function () {
    expect(array_keys(Artisan::all()))->toContain('excubia:check', 'excubia:push');
});

it('checks the application base path and exits by --fail-on', function (string $failOn, int $exitCode) {
    $this->app->setBasePath(cachedOutdatedProject());

    $this->artisan('excubia:check', [
        '--only' => 'php-eol',
        '--offline' => true,
        '--fail-on' => $failOn,
        '--no-banner' => true,
    ])
        ->expectsOutputToContain('Security support ended')
        ->assertExitCode($exitCode);
})->with([
    'critical fails' => ['critical', 1],
    'none passes' => ['none', 0],
]);

it('runs dependency-licenses from the lockfiles in Artisan', function () {
    $this->app->setBasePath(dirname(__DIR__).'/Fixtures/Projects/licenses');

    $this->artisan('excubia:check', [
        '--only' => 'dependency-licenses',
        '--offline' => true,
        '--fail-on' => 'high',
        '--no-banner' => true,
    ])
        ->expectsOutputToContain('License denied by checks.dependency-licenses.denied: SSPL-1.0.')
        ->assertExitCode(1);
});

it('runs env-example-sync on the application base path in Artisan, without reading .env', function () {
    $this->app->setBasePath(temporaryProject([
        'composer.lock' => '{"packages": [], "packages-dev": []}',
        'config/services.php' => "<?php\nreturn ['stripe' => env('STRIPE_SECRET')];\n",
        '.env.example' => "APP_NAME=Laravel\n",
        '.env' => "STRIPE_SECRET=artisan-sentinel-value\n",
    ]));

    $this->artisan('excubia:check', [
        '--only' => 'env-example-sync',
        '--offline' => true,
        '--fail-on' => 'low',
        '--no-banner' => true,
    ])
        ->expectsOutputToContain('STRIPE_SECRET is read by config/services.php but missing from .env.example.')
        ->doesntExpectOutputToContain('artisan-sentinel-value')
        ->assertExitCode(1);
});

it('exits 2 when the application has no composer.lock', function () {
    $this->app->setBasePath(temporaryProject([]));

    $this->artisan('excubia:check', ['--offline' => true, '--no-banner' => true])
        ->expectsOutputToContain('composer.lock')
        ->assertExitCode(2);
});

it('exits 2 from excubia:push when no excubia-app is configured', function () {
    putenv('EXCUBIA_APP_URL');
    putenv('EXCUBIA_APP_TOKEN');
    // No locked packages and no PHP constraint, so the scan makes no request.
    $this->app->setBasePath(temporaryProject([
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [], "packages-dev": []}',
    ]));

    $this->artisan('excubia:push')
        ->expectsOutputToContain('No excubia-app URL')
        ->assertExitCode(2);

    $this->artisan('excubia:push', ['--deploy' => true])
        ->expectsOutputToContain('No excubia-app URL')
        ->assertExitCode(2);
});

it('puts the Laravel environment in the excubia:check report and omits one that is not a short name', function () {
    $this->app->setBasePath(cachedOutdatedProject());
    $report = static function (): array {
        Artisan::call('excubia:check', ['--only' => 'php-eol', '--offline' => true, '--fail-on' => 'none', '--no-banner' => true, '--format' => 'json']);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    };

    expect($report()['environment'])->toBe('testing');

    $this->app['env'] = 'staging';

    expect($report()['environment'])->toBe('staging');

    $this->app['env'] = 'staging.example.com';

    expect($report())->not->toHaveKey('environment');
});

it('puts the release from the environment in the excubia:check report, without the git fallback', function () {
    $project = cachedOutdatedProject();
    file_put_contents($project.'/.git', 'gitdir: '.temporaryProject(['HEAD' => "0123456789abcdef0123456789abcdef01234567\n"])."\n");
    $this->app->setBasePath($project);
    $report = static function (): array {
        Artisan::call('excubia:check', ['--only' => 'php-eol', '--offline' => true, '--fail-on' => 'none', '--no-banner' => true, '--format' => 'json']);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    };

    expect($report())->not->toHaveKey('release');

    putenv('EXCUBIA_RELEASE=v5.1.0');

    try {
        expect($report()['release'])->toBe('v5.1.0');
    } finally {
        putenv('EXCUBIA_RELEASE');
    }
});
