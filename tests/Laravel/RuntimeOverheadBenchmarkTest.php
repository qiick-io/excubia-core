<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\RuntimeCollectorService;

/**
 * Per-request overhead of runtime monitoring. Not part of the default run
 * (group `benchmark`, excluded in phpunit.xml):
 *
 *     vendor/bin/pest --group=benchmark
 *
 * It prints the numbers and fails only if the collector cost per request
 * at the default sample rate is above 1 ms.
 */
const BENCHMARK_REQUESTS = 500;

function benchmarkProject(Application $app, bool $isEnabled): void
{
    $app->setBasePath(temporaryProject(['excubia.json' => json_encode(['runtime' => ['enabled' => $isEnabled]], JSON_THROW_ON_ERROR)]));
    $app->useStoragePath(temporaryProject([]));
    $app->register(ExcubiaServiceProvider::class, true);
}

/**
 * Mean milliseconds per request through the full HTTP kernel, terminate included.
 */
function meanRequestMilliseconds(mixed $test): float
{
    $test->get('/benchmark');
    $startedAt = hrtime(true);

    for ($i = 0; $i < BENCHMARK_REQUESTS; $i++) {
        $test->get('/benchmark');
    }

    return (hrtime(true) - $startedAt) / 1e6 / BENCHMARK_REQUESTS;
}

it('adds less than 1 ms per request at the default sample rate', function () {
    Route::get('/benchmark', fn () => 'ok')->name('benchmark');

    benchmarkProject($this->app, false);
    $disabled = meanRequestMilliseconds($this);

    benchmarkProject($this->app, true);
    $enabled = meanRequestMilliseconds($this);

    // The collector alone: one RequestHandled listener call and one flush to the buffer file.
    $collector = $this->app->make(RuntimeCollectorService::class);
    $event = new RequestHandled(Request::create('/benchmark'), new Response('ok'));
    $startedAt = hrtime(true);

    for ($i = 0; $i < BENCHMARK_REQUESTS; $i++) {
        $collector->recordRequest($event);
        $collector->flush();
    }

    $collectorOnly = (hrtime(true) - $startedAt) / 1e6 / BENCHMARK_REQUESTS;

    fwrite(STDERR, sprintf(
        "\nRuntime overhead over %d requests (PHP %s, Laravel %s)\n  disabled:  %.3f ms/request\n  enabled:   %.3f ms/request (delta %.3f ms)\n  collector: %.3f ms/request (listener + flush)\n",
        BENCHMARK_REQUESTS,
        PHP_VERSION,
        Application::VERSION,
        $disabled,
        $enabled,
        $enabled - $disabled,
        $collectorOnly,
    ));

    expect($collectorOnly)->toBeLessThan(1.0);
})->group('benchmark');
