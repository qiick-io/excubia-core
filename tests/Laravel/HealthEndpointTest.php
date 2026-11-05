<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\HealthEndpointService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Tester\CommandTester;

const HEALTH_SECRET = 'test-health-secret-0123456789abcdef';

/**
 * Point the app at a temporary project and storage, then boot the provider
 * again so it reads that project's `excubia.json`.
 *
 * @param  array<string, mixed>|null  $healthEndpoint  the `healthEndpoint` key, or null for no excubia.json
 * @param  array<string, string>  $files
 */
function bootHealthProject(Application $app, ?array $healthEndpoint, array $files = []): string
{
    if ($healthEndpoint !== null) {
        $files['excubia.json'] = json_encode([
            'cache' => ['directory' => temporaryProject([])],
            'healthEndpoint' => $healthEndpoint,
        ], JSON_THROW_ON_ERROR);
    }

    $project = temporaryProject($files);
    $app->setBasePath($project);
    $app->useStoragePath(temporaryProject([]));
    $app->register(ExcubiaServiceProvider::class, true);

    return $project;
}

/**
 * Outdated Laravel 10 lockfiles whose cache already holds the PHP end-of-life
 * response, so `excubia:check --offline --only=php-eol` needs no network.
 */
function outdatedHealthProject(Application $app): string
{
    $fixture = dirname(__DIR__).'/Fixtures/Projects/outdated-laravel-10';
    $project = bootHealthProject($app, ['enabled' => true], [
        'composer.json' => (string) file_get_contents($fixture.'/composer.json'),
        'composer.lock' => (string) file_get_contents($fixture.'/composer.lock'),
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

function hasHealthRoute(string $uri = 'excubia/health'): bool
{
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->uri() === $uri) {
            return true;
        }
    }

    return false;
}

beforeEach(fn () => putenv(HealthEndpointService::SECRET_VARIABLE.'='.HEALTH_SECRET));

afterEach(fn () => putenv(HealthEndpointService::SECRET_VARIABLE));

it('is disabled by default', function () {
    bootHealthProject($this->app, null);

    expect(hasHealthRoute())->toBeFalse();
    $this->get('/excubia/health', [HealthEndpointService::SECRET_HEADER => HEALTH_SECRET])->assertNotFound();
});

it('is not registered when enabled without a secret', function () {
    putenv(HealthEndpointService::SECRET_VARIABLE);
    bootHealthProject($this->app, ['enabled' => true]);

    expect(hasHealthRoute())->toBeFalse();
});

it('is not registered with a secret shorter than 32 characters, and the commands say so', function () {
    putenv(HealthEndpointService::SECRET_VARIABLE.'='.str_repeat('s', HealthEndpointService::MIN_SECRET_LENGTH - 1));
    bootHealthProject($this->app, ['enabled' => true], [
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [], "packages-dev": []}',
    ]);

    expect(hasHealthRoute())->toBeFalse()
        ->and(HealthEndpointService::hasShortSecret())->toBeTrue();
    $this->get('/excubia/health', [HealthEndpointService::SECRET_HEADER => str_repeat('s', HealthEndpointService::MIN_SECRET_LENGTH - 1)])->assertNotFound();
    $this->artisan('excubia:check', ['--offline' => true, '--no-banner' => true, '--fail-on' => 'none'])
        ->expectsOutputToContain(HealthEndpointService::SHORT_SECRET_WARNING)
        ->assertExitCode(0);
});

it('registers the route with a secret of exactly 32 characters and prints no warning', function () {
    $secret = str_repeat('s', HealthEndpointService::MIN_SECRET_LENGTH);
    putenv(HealthEndpointService::SECRET_VARIABLE.'='.$secret);
    bootHealthProject($this->app, ['enabled' => true], [
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [], "packages-dev": []}',
    ]);

    expect(hasHealthRoute())->toBeTrue();
    $this->get('/excubia/health', [HealthEndpointService::SECRET_HEADER => $secret])->assertOk();
    $this->artisan('excubia:check', ['--offline' => true, '--no-banner' => true, '--fail-on' => 'none'])
        ->doesntExpectOutputToContain(HealthEndpointService::SHORT_SECRET_WARNING)
        ->assertExitCode(0);
});

it('throttles secret guesses with the throttle middleware', function () {
    bootHealthProject($this->app, ['enabled' => true]);

    $route = collect(Route::getRoutes()->getRoutes())->first(fn (Illuminate\Routing\Route $route): bool => $route->uri() === 'excubia/health');

    expect($route?->middleware())->toContain(HealthEndpointService::THROTTLE_MIDDLEWARE);

    for ($attempt = 0; $attempt < 60; $attempt++) {
        $this->get('/excubia/health', [HealthEndpointService::SECRET_HEADER => 'guess-'.$attempt])->assertNotFound();
    }

    $this->get('/excubia/health', [HealthEndpointService::SECRET_HEADER => HEALTH_SECRET])->assertTooManyRequests();
});

it('is not registered when excubia.json is invalid, and the app still boots', function () {
    bootHealthProject($this->app, ['enabled' => 'yes']);

    expect(hasHealthRoute())->toBeFalse();
});

it('registers the configured path', function () {
    bootHealthProject($this->app, ['enabled' => true, 'path' => '/internal/health/']);

    expect(hasHealthRoute('internal/health'))->toBeTrue()
        ->and(hasHealthRoute())->toBeFalse();
});

it('answers 404 to a wrong or missing secret', function (?string $secret) {
    bootHealthProject($this->app, ['enabled' => true]);

    $headers = $secret === null ? [] : [HealthEndpointService::SECRET_HEADER => $secret];

    $this->get('/excubia/health', $headers)->assertNotFound();
})->with([
    'missing' => [null],
    'wrong' => ['wrong-secret'],
    'empty' => [''],
    'prefix' => ['test-health'],
]);

it('answers a single skipped entry before the first run', function () {
    bootHealthProject($this->app, ['enabled' => true]);

    $response = $this->get('/excubia/health', [HealthEndpointService::SECRET_HEADER => HEALTH_SECRET]);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonCount(1, 'checkResults')
        ->assertJsonPath('checkResults.0.name', 'excubia-run')
        ->assertJsonPath('checkResults.0.status', 'skipped');
    expect($response->json('finishedAt'))->toBeInt();
});

it('serves the result stored by excubia:check without running checks', function () {
    $project = outdatedHealthProject($this->app);

    $this->artisan('excubia:check', ['--only' => 'php-eol', '--offline' => true, '--fail-on' => 'none', '--no-banner' => true])
        ->assertExitCode(0);

    $response = $this->get('/excubia/health', [HealthEndpointService::SECRET_HEADER => HEALTH_SECRET]);

    $response->assertOk()
        ->assertJsonStructure([
            'finishedAt',
            'checkResults' => [['name', 'label', 'status', 'notificationMessage', 'shortSummary', 'meta']],
        ])
        ->assertJsonPath('checkResults.0.name', 'php-eol')
        ->assertJsonPath('checkResults.0.status', 'failed')
        ->assertJsonPath('checkResults.0.meta.highestSeverity', 'critical');
    expect($response->getContent())->not->toContain($project)
        ->and($response->getContent())->not->toContain(sys_get_temp_dir())
        ->and($response->getContent())->not->toContain(HEALTH_SECRET)
        ->and(abs((int) $response->json('finishedAt') - time()))->toBeLessThan(60);
});

it('stores crashed when a run ends without a report', function () {
    bootHealthProject($this->app, ['enabled' => true]);

    $this->artisan('excubia:check', ['--offline' => true, '--no-banner' => true])->assertExitCode(2);

    $this->get('/excubia/health', [HealthEndpointService::SECRET_HEADER => HEALTH_SECRET])
        ->assertOk()
        ->assertJsonCount(1, 'checkResults')
        ->assertJsonPath('checkResults.0.status', 'crashed');
});

it('stores crashed and rethrows when a run throws', function () {
    bootHealthProject($this->app, ['enabled' => true]);

    expect(fn () => HealthEndpointService::recording($this->app, null, function (): int {
        throw new RuntimeException('A check threw at /srv/app/vendor.');
    }))->toThrow(RuntimeException::class);

    $response = $this->get('/excubia/health', [HealthEndpointService::SECRET_HEADER => HEALTH_SECRET]);

    $response->assertJsonPath('checkResults.0.status', 'crashed');
    expect($response->getContent())->not->toContain('/srv/app');
});

it('stores the scan of excubia:push even when the push is not configured', function () {
    putenv('EXCUBIA_APP_URL');
    putenv('EXCUBIA_APP_TOKEN');
    // No locked packages and no PHP constraint, so the scan makes no request.
    bootHealthProject($this->app, ['enabled' => true], [
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [], "packages-dev": []}',
    ]);

    $this->artisan('excubia:push')->assertExitCode(2);

    $response = $this->get('/excubia/health', [HealthEndpointService::SECRET_HEADER => HEALTH_SECRET]);

    $response->assertOk();
    expect(array_column($response->json('checkResults'), 'name'))->toContain('php-eol');
});

it('writes nothing when the endpoint is disabled', function () {
    bootHealthProject($this->app, ['enabled' => false], [
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [], "packages-dev": []}',
    ]);

    $this->artisan('excubia:check', ['--offline' => true, '--no-banner' => true, '--fail-on' => 'none'])->assertExitCode(0);

    expect(is_dir($this->app->storagePath('excubia')))->toBeFalse();
});
