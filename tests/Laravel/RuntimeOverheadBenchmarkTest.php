<?php

declare(strict_types=1);

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\RuntimeCollectorService;

/**
 * Per-request overhead of runtime monitoring. Not part of the default run
 * (group `benchmark`, excluded in phpunit.xml):
 *
 *     vendor/bin/pest --group=benchmark
 *
 * It prints the numbers and fails only if the collector cost per request
 * with every type on, at the default sample rate, is above 1 ms. It also
 * prints the cost of one latency histogram sample (#147). The
 * benchmark route reads and writes the cache three times and logs once, so
 * the cache and log level listeners run as in a typical request.
 */
const BENCHMARK_REQUESTS = 500;

/**
 * @param  array<string, mixed>  $runtime
 */
function benchmarkProject(Application $app, array $runtime): void
{
    $app->setBasePath(temporaryProject(['excubia.json' => json_encode(['runtime' => $runtime], JSON_THROW_ON_ERROR)]));
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

it('adds less than 1 ms per request with every type on at the default sample rate', function () {
    config(['logging.default' => 'null']);
    Route::get('/benchmark', function () {
        Cache::get('settings:site');
        Cache::put('settings:site', 'value');
        Cache::get('settings:site');
        Log::info('benchmark');

        return 'ok';
    })->name('benchmark');
    $allTypes = ['enabled' => true];

    foreach (RuntimeTypeEnum::cases() as $type) {
        $allTypes[$type->configurationKey()] = ['enabled' => true];
    }

    benchmarkProject($this->app, ['enabled' => false]);
    $disabled = meanRequestMilliseconds($this);

    benchmarkProject($this->app, ['enabled' => true]);
    $defaultTypes = meanRequestMilliseconds($this);

    benchmarkProject($this->app, $allTypes);
    $enabled = meanRequestMilliseconds($this);

    // The collector alone, per request: RequestHandled (request and memory), three cache events, one log entry and one flush.
    $collector = $this->app->make(RuntimeCollectorService::class);
    $event = new RequestHandled(Request::create('/benchmark'), new Response('ok'));
    $cacheEvents = [new CacheMissed('array', 'settings:site'), new KeyWritten('array', 'settings:site', 'value'), new CacheHit('array', 'settings:site', 'value')];
    $logEvent = new MessageLogged('info', 'benchmark');
    $startedAt = hrtime(true);

    for ($i = 0; $i < BENCHMARK_REQUESTS; $i++) {
        $collector->recordRequest($event);

        foreach ($cacheEvents as $cacheEvent) {
            $collector->recordCache($cacheEvent);
        }

        $collector->recordLog($logEvent);
        $collector->flush();
    }

    $collectorOnly = (hrtime(true) - $startedAt) / 1e6 / BENCHMARK_REQUESTS;

    // One latency histogram sample (#147): find the bucket and increment it, as the collector does per timed event.
    $histogram = array_fill(0, count(RuntimeTypeEnum::HISTOGRAM_BOUNDS_MS) + 1, 0);
    $samples = 100 * BENCHMARK_REQUESTS;
    $startedAt = hrtime(true);

    for ($i = 0; $i < $samples; $i++) {
        $histogram[RuntimeTypeEnum::histogramBucket((float) ($i % 12000))]++;
    }

    $perHistogramSample = (hrtime(true) - $startedAt) / 1e6 / $samples;

    fwrite(STDERR, sprintf(
        "\nRuntime overhead over %d requests (PHP %s, Laravel %s)\n  disabled:      %.3f ms/request\n  default types: %.3f ms/request (delta %.3f ms)\n  all types:     %.3f ms/request (delta %.3f ms)\n  collector:     %.3f ms/request (all listeners + flush)\n  histogram:     %.5f ms/sample (bucket lookup + increment)\n",
        BENCHMARK_REQUESTS,
        PHP_VERSION,
        Application::VERSION,
        $disabled,
        $defaultTypes,
        $defaultTypes - $disabled,
        $enabled,
        $enabled - $disabled,
        $collectorOnly,
        $perHistogramSample,
    ));

    expect($collectorOnly)->toBeLessThan(1.0)
        ->and(array_sum($histogram))->toBe($samples);
})->group('benchmark');

it('costs nothing on a request that raises no deprecation, with deprecations on', function () {
    config(['logging.default' => 'null']);
    Route::get('/benchmark', fn () => 'ok')->name('benchmark');
    $without = [];
    $with = [];

    $deprecationsRaised = 0;
    set_error_handler(static function (int $level) use (&$deprecationsRaised): bool {
        $deprecationsRaised += $level === E_DEPRECATED || $level === E_USER_DEPRECATED ? 1 : 0;

        return false;
    });

    // Every boot adds the provider's listeners again, so later rounds are slower: alternate
    // which setup goes first so that drift hits both alike.
    for ($round = 0; $round < 6; $round++) {
        foreach ($round % 2 === 0 ? [false, true] : [true, false] as $isOn) {
            benchmarkProject($this->app, ['enabled' => true, 'deprecations' => ['enabled' => $isOn]]);

            if ($isOn) {
                $with[] = meanRequestMilliseconds($this);
                restore_error_handler(); // unchain this round's handler so rounds do not stack
            } else {
                $without[] = meanRequestMilliseconds($this);
            }
        }
    }

    restore_error_handler();
    sort($without);
    sort($with);
    $withoutMedian = ($without[2] + $without[3]) / 2;
    $withMedian = ($with[2] + $with[3]) / 2;

    // The handler alone per triggered deprecation, in front of a handler that does nothing.
    $collector = $this->app->make(RuntimeCollectorService::class);
    set_error_handler(static fn (): bool => true);
    $collector->listenToDeprecations();
    $startedAt = hrtime(true);

    for ($i = 0; $i < BENCHMARK_REQUESTS; $i++) {
        trigger_error('Benchmark::old() is deprecated', E_USER_DEPRECATED);
    }

    $perDeprecation = (hrtime(true) - $startedAt) / 1e6 / BENCHMARK_REQUESTS;
    restore_error_handler();
    restore_error_handler();
    $collector->flush();

    fwrite(STDERR, sprintf(
        "\nDeprecation hook over 6 x %d requests that raise no deprecation (%d raised; PHP %s, Laravel %s, medians)\n  deprecations off: %.3f ms/request\n  deprecations on:  %.3f ms/request (delta %.3f ms)\n  per deprecation:  %.4f ms (hook + record, previous handler a no-op)\n",
        BENCHMARK_REQUESTS,
        $deprecationsRaised,
        PHP_VERSION,
        Application::VERSION,
        $withoutMedian,
        $withMedian,
        $withMedian - $withoutMedian,
        $perDeprecation,
    ));

    expect($deprecationsRaised)->toBe(0)
        ->and($perDeprecation)->toBeLessThan(0.1);
})->group('benchmark');
