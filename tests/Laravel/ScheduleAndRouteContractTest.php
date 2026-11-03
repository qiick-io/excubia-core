<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\HealthEndpointService;

/*
 * The scheduled entries and the health route the provider registers are
 * public contracts (docs/CONTRACTS.md). The snapshot is the `laravel` key
 * of tests/Fixtures/Contracts/public-contracts.json.
 */

afterEach(fn () => putenv(HealthEndpointService::SECRET_VARIABLE));

it('freezes the scheduled entries and the health route with every opt-in on', function (): void {
    putenv(HealthEndpointService::SECRET_VARIABLE.'='.str_repeat('s', HealthEndpointService::MIN_SECRET_LENGTH));
    $this->app->setBasePath(temporaryProject([
        'excubia.json' => json_encode([
            'healthEndpoint' => ['enabled' => true],
            'runtime' => ['enabled' => true],
            'exceptions' => ['enabled' => true],
        ], JSON_THROW_ON_ERROR),
    ]));
    $this->app->useStoragePath(temporaryProject([]));
    $this->app->register(ExcubiaServiceProvider::class, true);

    $schedule = [];

    foreach ($this->app->make(Schedule::class)->events() as $event) {
        if (preg_match('/excubia:[a-z-]+/', (string) $event->command, $match) === 1) {
            $schedule[$match[0]] = $event->expression;
        }
    }

    ksort($schedule);
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'excubia.')) {
            $routes[(string) $route->getName()] = ['uri' => $route->uri(), 'methods' => $route->methods()];
        }
    }

    expect([
        'schedule' => $schedule,
        'routes' => $routes,
    ])->toBe(contractSnapshot('laravel'), CONTRACT_CHANGE_MESSAGE);
});
