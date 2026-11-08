<?php

declare(strict_types=1);

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Ai;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Tools\ToolNameResolver;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\WorkerStarting;
use Monolog\Handler\NullHandler;
use Monolog\Logger as Monolog;
use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\RuntimeCollectorService;
use Qiick\Excubia\Tests\Support\FakeAiAgent;
use Qiick\Excubia\Tests\Support\FakeAiTool;

require_once dirname(__DIR__).'/Support/FakeOctaneEvents.php';

/**
 * Per-request overhead of runtime monitoring. Not part of the default run
 * (group `benchmark`, excluded in phpunit.xml):
 *
 *     vendor/bin/pest --group=benchmark
 *
 * It prints the numbers and fails only if the collector cost per request
 * with every type on, at the default sample rate, is above 1 ms. It also
 * prints the cost of one latency histogram sample (#147) and of one query
 * counted for duplicate query detection (#148). The benchmark route reads
 * and writes the cache three times, logs once and runs the same query five
 * times (an N+1 that `duplicateQuery` records), so the cache, log level and
 * query listeners run as in a typical request. The third test prices the
 * Monolog processor that learns the log channel (#174) per log entry, and the
 * fourth the `ai` and `aiTool` listeners (#198) per Laravel AI SDK agent run,
 * and the fifth the `octane` listeners (#213) per Octane request and tick.
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

        for ($i = 0; $i < 5; $i++) {
            DB::select('select ? as id', [$i]);
        }

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

    // The collector alone, per request: RequestHandled (request, memory and duplicate queries), three cache events, one log entry, five queries and one flush.
    $collector = $this->app->make(RuntimeCollectorService::class);
    $event = new RequestHandled(Request::create('/benchmark'), new Response('ok'));
    $cacheEvents = [new CacheMissed('array', 'settings:site'), new KeyWritten('array', 'settings:site', 'value'), new CacheHit('array', 'settings:site', 'value')];
    $logEvent = new MessageLogged('info', 'benchmark');
    $queryEvent = new QueryExecuted('select * from "posts" where "posts"."user_id" = ? and "posts"."user_id" is not null', [1], 0.5, DB::connection());
    $startedAt = hrtime(true);

    for ($i = 0; $i < BENCHMARK_REQUESTS; $i++) {
        for ($query = 0; $query < 5; $query++) {
            $collector->recordQuery($queryEvent);
        }

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

    // One query for duplicate query detection (#148): a repeated query (normalised once, then counted) and the normalisation itself (a query seen for the first time).
    $startedAt = hrtime(true);

    for ($i = 0; $i < $samples; $i++) {
        $collector->recordQuery($queryEvent);
    }

    $perRepeatedQuery = (hrtime(true) - $startedAt) / 1e6 / $samples;
    $collector->forgetQueryShapes();
    $startedAt = hrtime(true);

    for ($i = 0; $i < BENCHMARK_REQUESTS; $i++) {
        RuntimeCollectorService::normaliseSql($queryEvent->sql.' and "posts"."id" in (?, ?, ?) limit 10');
    }

    $perNormalisation = (hrtime(true) - $startedAt) / 1e6 / BENCHMARK_REQUESTS;
    $collector->flush();

    fwrite(STDERR, sprintf(
        "\nRuntime overhead over %d requests (PHP %s, Laravel %s)\n  disabled:      %.3f ms/request\n  default types: %.3f ms/request (delta %.3f ms)\n  all types:     %.3f ms/request (delta %.3f ms)\n  collector:     %.3f ms/request (all listeners + flush)\n  histogram:     %.5f ms/sample (bucket lookup + increment)\n  query shape:   %.5f ms/query repeated (memo + count), %.5f ms/new query (normalise)\n",
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
        $perRepeatedQuery,
        $perNormalisation,
    ));

    expect($collectorOnly)->toBeLessThan(1.0)
        ->and($perRepeatedQuery)->toBeLessThan(0.01)
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

it('adds little per log entry to learn its channel, with log levels on', function () {
    benchmarkProject($this->app, ['enabled' => true, 'logLevels' => ['enabled' => true]]);
    $collector = $this->app->make(RuntimeCollectorService::class);
    $entries = 40 * BENCHMARK_REQUESTS;

    // One Monolog write to a NullHandler without and with the channel processor (#174), no event dispatcher.
    $writeMilliseconds = function (Logger $logger) use ($entries): float {
        $startedAt = hrtime(true);

        for ($i = 0; $i < $entries; $i++) {
            $logger->info('benchmark');
        }

        return (hrtime(true) - $startedAt) / 1e6 / $entries;
    };
    $plain = $writeMilliseconds(new Logger(new Monolog('benchmark', [new NullHandler])));
    $watched = new Logger(new Monolog('benchmark', [new NullHandler]));
    $collector->watchLogChannel($watched, 'benchmark');
    $withProcessor = $writeMilliseconds($watched);

    // The listener per entry once the channel is known: read it, clear it and count it.
    $logEvent = new MessageLogged('info', 'benchmark');
    $startedAt = hrtime(true);

    for ($i = 0; $i < $entries; $i++) {
        $watched->info('benchmark');
        $collector->recordLog($logEvent);
    }

    $perEntry = (hrtime(true) - $startedAt) / 1e6 / $entries;
    $collector->flush();

    fwrite(STDERR, sprintf(
        "\nLog channel over %d entries (PHP %s, Laravel %s)\n  write, no processor:   %.5f ms/entry\n  write, with processor: %.5f ms/entry (delta %.5f ms)\n  write + recordLog:     %.5f ms/entry\n",
        $entries,
        PHP_VERSION,
        Application::VERSION,
        $plain,
        $withProcessor,
        $withProcessor - $plain,
        $perEntry,
    ));

    expect(bufferedOfType($this->app, 'logLevel')[0])->toMatchArray(['channel' => 'benchmark', 'count' => $entries])
        ->and($withProcessor - $plain)->toBeLessThan(0.01);
})->group('benchmark');

it('adds little per Laravel AI SDK agent run and tool call, with the AI types on', function () {
    $this->app->register(AiServiceProvider::class);
    $runs = 4 * BENCHMARK_REQUESTS;
    $toolName = ToolNameResolver::resolve(new FakeAiTool);

    // One agent run through the SDK's fake gateway: a tool call, then the answer.
    $runMilliseconds = function () use ($runs, $toolName): float {
        FakeAiAgent::fake(function (string $prompt, $attachments, $provider, string $model) use ($toolName): mixed {
            static $step = 0;

            return $step++ % 2 === 0
                ? new ToolCall('call', $toolName, ['query' => 'order'])
                : new TextResponse('done', new TextUsage(100, 20, 60), new Meta('openai', $model));
        });
        $agent = new FakeAiAgent;
        $agent->prompt('warm up', model: 'gpt-test');
        $startedAt = hrtime(true);

        for ($i = 0; $i < $runs; $i++) {
            $agent->prompt('benchmark', model: 'gpt-test');
        }

        return (hrtime(true) - $startedAt) / 1e6 / $runs;
    };

    benchmarkProject($this->app, ['enabled' => true]);
    $off = $runMilliseconds();
    benchmarkProject($this->app, ['enabled' => true, 'ai' => ['enabled' => true], 'aiTools' => ['enabled' => true]]);
    $on = $runMilliseconds();

    // The collector alone per run: start, done with its usage, one tool call.
    $collector = $this->app->make(RuntimeCollectorService::class);
    $prompt = new AgentPrompt(new FakeAiAgent, 'benchmark', [], Ai::textProvider('openai'), 'gpt-test');
    $done = new AgentPrompted('invocation', $prompt, new AgentResponse('invocation', 'done', new TextUsage(100, 20, 60), new Meta('openai', 'gpt-test')));
    $tool = new ToolInvoked('invocation', 'tool', new FakeAiAgent, new FakeAiTool, ['query' => 'order'], 'result', 0.2);
    $start = new PromptingAgent('invocation', $prompt);
    $startedAt = hrtime(true);

    for ($i = 0; $i < $runs; $i++) {
        $collector->startAiOperation($start);
        $collector->recordAiTool($tool);
        $collector->recordAiOperation($done);
    }

    $collectorOnly = (hrtime(true) - $startedAt) / 1e6 / $runs;
    $collector->flush();

    fwrite(STDERR, sprintf(
        "\nLaravel AI SDK over %d agent runs with one tool call each (fake gateway; PHP %s, Laravel %s)\n  ai types off: %.4f ms/run\n  ai types on:  %.4f ms/run (delta %.4f ms)\n  collector:    %.4f ms/run (start + tool + done, no flush)\n",
        $runs,
        PHP_VERSION,
        Application::VERSION,
        $off,
        $on,
        $on - $off,
        $collectorOnly,
    ));

    expect($collectorOnly)->toBeLessThan(0.05);
})->group('benchmark');

it('adds little per Octane request and tick, with the octane type on', function () {
    $requests = 4 * BENCHMARK_REQUESTS;
    $app = $this->app;
    $request = Request::create('/benchmark');
    $received = new RequestReceived($app, $app, $request);
    $terminated = new RequestTerminated($app, $app, $request, new Response('ok'));
    $tick = new TickReceived($app, $app);

    // Octane's events through the dispatcher, as the worker fires them.
    $dispatchMilliseconds = function () use ($app, $requests, $received, $terminated, $tick): float {
        $events = $app->make('events');
        $events->dispatch(new WorkerStarting($app));
        $startedAt = hrtime(true);

        for ($i = 0; $i < $requests; $i++) {
            $events->dispatch($received);
            $events->dispatch($terminated);
            $events->dispatch($tick);
        }

        return (hrtime(true) - $startedAt) / 1e6 / $requests;
    };

    benchmarkProject($this->app, ['enabled' => true]);
    $off = $dispatchMilliseconds();
    benchmarkProject($this->app, ['enabled' => true, 'octane' => ['enabled' => true]]);
    $on = $dispatchMilliseconds();

    // The collector alone: one request (received + terminated) and one tick.
    $collector = $this->app->make(RuntimeCollectorService::class);
    $startedAt = hrtime(true);

    for ($i = 0; $i < $requests; $i++) {
        $collector->receiveOctaneRequest($received);
        $collector->recordOctaneRequest($terminated);
        $collector->recordOctaneOperation($tick);
    }

    $collectorOnly = (hrtime(true) - $startedAt) / 1e6 / $requests;
    $collector->flush();

    fwrite(STDERR, sprintf(
        "\nLaravel Octane over %d requests and ticks (faked events; PHP %s, Laravel %s)\n  octane off: %.4f ms/request\n  octane on:  %.4f ms/request (delta %.4f ms)\n  collector:  %.4f ms/request (received + terminated + tick, no flush)\n",
        $requests,
        PHP_VERSION,
        Application::VERSION,
        $off,
        $on,
        $on - $off,
        $collectorOnly,
    ));

    expect($collectorOnly)->toBeLessThan(0.05);
})->group('benchmark');
