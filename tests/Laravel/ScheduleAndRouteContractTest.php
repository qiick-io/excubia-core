<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Qiick\Excubia\Laravel\ExcubiaHeartbeatCommand;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\HealthEndpointService;

/*
 * The scheduled entries and the health route the provider registers are
 * public contracts (docs/CONTRACTS.md). The snapshot is the `laravel` key
 * of tests/Fixtures/Contracts/public-contracts.json.
 */

const CONTRACT_DOCUMENT_HEARTBEAT_MESSAGE = 'docs/CONTRACTS.md names a different scheduler heartbeat cache key than ExcubiaHeartbeatCommand::CACHE_KEY: '
    .'update the doc and tests/Fixtures/Contracts/public-contracts.json together, and see docs/VERSIONING.md.';

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

it('stores the scheduler heartbeat under the key and in the format docs/CONTRACTS.md names', function (): void {
    $document = (string) file_get_contents(dirname(__DIR__, 2).'/docs/CONTRACTS.md');
    $isDocumented = preg_match('/^Scheduler heartbeat \(#205\): `excubia:heartbeat` stores the time of its run under the cache key `([^`]+)` in the app\'s default cache store\. Value: an integer, the Unix time in seconds \(UTC\); no expiry, overwritten on every run\./m', $document, $match);

    expect($isDocumented)->toBe(1, 'docs/CONTRACTS.md no longer documents the scheduler heartbeat key and value format (#205).')
        ->and($match[1])->toBe(ExcubiaHeartbeatCommand::CACHE_KEY, CONTRACT_DOCUMENT_HEARTBEAT_MESSAGE)
        ->and(contractSnapshot('artisan')['heartbeatCacheKey'])->toBe(ExcubiaHeartbeatCommand::CACHE_KEY, CONTRACT_CHANGE_MESSAGE);

    Cache::forget(ExcubiaHeartbeatCommand::CACHE_KEY);
    $before = time();

    Artisan::call('excubia:heartbeat');

    $value = Cache::get(ExcubiaHeartbeatCommand::CACHE_KEY);

    expect($value)->toBeInt()
        ->and($value)->toBeGreaterThanOrEqual($before)
        ->and($value)->toBeLessThanOrEqual(time());
});
