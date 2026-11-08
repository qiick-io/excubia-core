<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use JsonSchema\Validator;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Qiick\Excubia\Checks\LaravelOctaneConfigCheck;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Data\RuntimeConfigurationData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\LaravelPostureService;
use Qiick\Excubia\Laravel\RuntimeCollectorService;
use Qiick\Excubia\Services\RuntimeBufferService;
use Symfony\Component\HttpFoundation\Response;

require_once dirname(__DIR__).'/Support/FakeOctaneEvents.php';

/*
 * Laravel Octane (#213): the `octane` runtime type and the
 * `laravel-octane-config` check, with Octane's events faked under their real
 * names (tests/Support/FakeOctaneEvents.php): laravel/octane is not a
 * dependency.
 */
const OCTANE_MARKER = 'excubia-octane-marker-5c1e';

/**
 * Boot Excubia with `runtime.octane` on, plus these keys.
 *
 * @param  array<string, mixed>  $runtime
 */
function bootOctaneRuntime(Application $app, array $runtime = []): void
{
    bootOctaneProject($app, ['enabled' => true, 'octane' => ['enabled' => true], ...$runtime]);
}

/**
 * Point the app at a temporary project and storage with this `runtime` key,
 * then boot the provider again so it reads that `excubia.json`.
 *
 * @param  array<string, mixed>  $runtime
 */
function bootOctaneProject(Application $app, array $runtime): void
{
    $app->setBasePath(temporaryProject(['excubia.json' => json_encode(['runtime' => $runtime], JSON_THROW_ON_ERROR)]));
    $app->useStoragePath(temporaryProject([]));
    $app->register(ExcubiaServiceProvider::class, true);
}

/**
 * Errors of the buffered aggregates against docs/runtime-schema.json.
 *
 * @return list<string>
 */
function octaneRuntimeSchemaErrors(Application $app): array
{
    $validator = new Validator;
    $document = json_decode(json_encode(['bucketSeconds' => 60, 'aggregates' => bufferedRuntime($app)], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $validator->validate($document, (object) ['$ref' => 'file://'.dirname(__DIR__, 2).'/docs/runtime-schema.json']);

    return array_map(fn (array $error): string => $error['property'].': '.$error['message'], $validator->getErrors());
}

/**
 * A request full of data that must never reach the buffer.
 */
function octaneRequest(): Request
{
    return Request::create(
        'https://'.OCTANE_MARKER.'.example.test/orders/'.OCTANE_MARKER.'?token='.OCTANE_MARKER,
        'POST',
        ['email' => OCTANE_MARKER.'@example.test'],
        ['session' => OCTANE_MARKER],
        [],
        ['HTTP_AUTHORIZATION' => 'Bearer '.OCTANE_MARKER, 'REMOTE_ADDR' => '203.0.113.7'],
    );
}

/**
 * One request through the worker: received, then terminated.
 */
function octaneHandleRequest(Application $app): void
{
    $request = octaneRequest();
    Event::dispatch(new RequestReceived($app, $app, $request));
    Event::dispatch(new RequestTerminated($app, $app, $request, new Response(OCTANE_MARKER, 200)));
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function bufferedOctane(Application $app): Collection
{
    return bufferedOfType($app, 'octane');
}

/**
 * @return array{0: int, 1: int}
 */
function octaneListenerCounts(): array
{
    return [count(Event::getListeners(WorkerStarting::class)), count(Event::getListeners(RequestTerminated::class))];
}

it('keeps the octane type off by default and listens to Octane only when switched on', function (array $runtime, int $added) {
    $before = octaneListenerCounts();
    bootOctaneProject($this->app, $runtime);

    expect(octaneListenerCounts())->toBe([$before[0] + $added, $before[1] + $added]);
})->with([
    'runtime on, octane not switched on' => [['enabled' => true], 0],
    'runtime off' => [['enabled' => false, 'octane' => ['enabled' => true]], 0],
    'octane on' => [['enabled' => true, 'octane' => ['enabled' => true]], 1],
]);

it('records a worker\'s starts, requests, tasks, ticks, errors and stop in one entry per server, Octane server and worker type', function () {
    putenv('EXCUBIA_SERVER_NAME=web-1');
    config(['octane.server' => 'swoole']);
    bootOctaneRuntime($this->app);
    $app = $this->app;

    Event::dispatch(new WorkerStarting($app));
    octaneHandleRequest($app);
    octaneHandleRequest($app);
    Event::dispatch(new TaskReceived($app, $app, fn (): string => OCTANE_MARKER));
    Event::dispatch(new TickReceived($app, $app));
    Event::dispatch(new RequestReceived($app, $app, octaneRequest()));
    Event::dispatch(new WorkerErrorOccurred(new RuntimeException(OCTANE_MARKER), $app));
    Event::dispatch(new WorkerErrorOccurred(new RuntimeException(OCTANE_MARKER), $app));
    Event::dispatch(new WorkerStopping($app));
    putenv('EXCUBIA_SERVER_NAME');

    $entries = bufferedOctane($app);
    $entry = $entries->first();

    expect($entries)->toHaveCount(1)
        ->and($entry)->toMatchArray([
            'server' => 'web-1',
            'octaneServer' => 'swoole',
            'workerType' => 'http',
            'count' => 8,
            'errorCount' => 2,
            'workerStarts' => 1,
            'workerStops' => 1,
            'requests' => 3,
            'tasks' => 1,
            'ticks' => 1,
            'totalRequestsAtStop' => 3,
            'maxRequestsAtStop' => 3,
        ])
        ->and($entry['maxMb'])->toBeGreaterThan(0)
        ->and(octaneRuntimeSchemaErrors($app))->toBe([]);
});

it('reports the memory after each request and its growth since the worker\'s first request', function () {
    bootOctaneRuntime($this->app);
    $app = $this->app;

    Event::dispatch(new WorkerStarting($app));
    octaneHandleRequest($app);
    $leak = str_repeat('x', 4 * 1048576);
    octaneHandleRequest($app);
    Event::dispatch(new WorkerStopping($app));

    $entry = bufferedOctane($app)->first();

    expect(strlen($leak))->toBe(4 * 1048576)
        ->and($entry['maxGrowthMb'])->toBeGreaterThanOrEqual(3.9)
        ->and($entry['maxMb'])->toBeGreaterThan($entry['maxGrowthMb']);
});

it('starts counting again, growth base included, when the worker starts again', function () {
    bootOctaneRuntime($this->app);
    $app = $this->app;

    Event::dispatch(new WorkerStarting($app));
    octaneHandleRequest($app);
    octaneHandleRequest($app);
    Event::dispatch(new WorkerStopping($app));
    Event::dispatch(new WorkerStarting($app));
    octaneHandleRequest($app);
    Event::dispatch(new WorkerStopping($app));

    expect(bufferedOctane($app)->first())->toMatchArray([
        'workerStarts' => 2,
        'workerStops' => 2,
        'requests' => 3,
        'totalRequestsAtStop' => 3,
        'maxRequestsAtStop' => 2,
    ]);
});

it('tells a Swoole task worker from an http worker and names the server from the Octane client', function () {
    config(['octane.server' => 'swoole']);
    bootOctaneRuntime($this->app);
    $app = $this->app;
    $app->instance('Swoole\Http\Server', (object) ['taskworker' => true]);

    Event::dispatch(new WorkerStarting($app));
    Event::dispatch(new TaskReceived($app, $app, OCTANE_MARKER));
    Event::dispatch(new TickReceived($app, $app));
    Event::dispatch(new WorkerStopping($app));

    expect(bufferedOctane($app)->first())->toMatchArray(['octaneServer' => 'swoole', 'workerType' => 'task', 'tasks' => 1, 'ticks' => 1, 'requests' => 0]);

    $client = new class {};

    if (! class_exists('Laravel\Octane\RoadRunner\RoadRunnerClient')) {
        class_alias($client::class, 'Laravel\Octane\RoadRunner\RoadRunnerClient');
    }

    bootOctaneRuntime($app);
    $app->forgetInstance('Swoole\Http\Server');
    $app->instance('Laravel\Octane\Contracts\Client', $client);
    Event::dispatch(new WorkerStarting($app));

    expect(bufferedOctane($app)->first())->toMatchArray(['octaneServer' => 'roadrunner', 'workerType' => 'http']);
});

it('names an Octane server it does not know unknown', function () {
    config(['octane.server' => OCTANE_MARKER]);
    bootOctaneRuntime($this->app);

    Event::dispatch(new WorkerStarting($this->app));

    expect(bufferedOctane($this->app)->first()['octaneServer'])->toBe('unknown');
});

it('flushes when a worker starts, errs or stops, and tasks and ticks at most once a minute', function () {
    bootOctaneProject($this->app, ['enabled' => true]);
    $app = $this->app;
    $now = 1_800_000_000.0;
    $collector = new RuntimeCollectorService(
        new RuntimeConfigurationData(isEnabled: true, enabledTypes: ['octane' => true]),
        new RuntimeBufferService($app->storagePath('excubia/runtime'), RuntimeConfigurationData::DEFAULT_BUFFER_MAX_BYTES),
        $app->basePath(),
        function () use (&$now): float {
            return $now;
        },
    );

    $collector->startOctaneWorker(new WorkerStarting($app));
    expect(bufferedOctane($app)->sum('workerStarts'))->toBe(1);

    $collector->recordOctaneOperation(new TickReceived($app, $app));
    expect(bufferedOctane($app)->sum('ticks'))->toBe(0);

    $now += 61;
    $collector->recordOctaneOperation(new TickReceived($app, $app));
    expect(bufferedOctane($app)->sum('ticks'))->toBe(2);

    $collector->recordOctaneError(new WorkerErrorOccurred(new RuntimeException(OCTANE_MARKER), $app));
    expect(bufferedOctane($app)->sum('errorCount'))->toBe(1);

    $collector->recordOctaneOperation(new TaskReceived($app, $app, OCTANE_MARKER));
    $collector->stopOctaneWorker(new WorkerStopping($app));
    expect(bufferedOctane($app)->sum('tasks'))->toBe(1)
        ->and(bufferedOctane($app)->sum('workerStops'))->toBe(1);
});

it('never writes URLs, request, response or task data, exception messages, PIDs or the hostname to the buffer', function () {
    bootOctaneRuntime($this->app);
    $app = $this->app;

    Event::dispatch(new WorkerStarting($app));
    octaneHandleRequest($app);
    Event::dispatch(new TaskReceived($app, $app, ['secret' => OCTANE_MARKER]));
    Event::dispatch(new RequestReceived($app, $app, octaneRequest()));
    Event::dispatch(new WorkerErrorOccurred(new RuntimeException(OCTANE_MARKER.' at /srv/'.OCTANE_MARKER.'/app.php'), $app));
    Event::dispatch(new WorkerStopping($app));

    $raw = (string) file_get_contents($app->storagePath('excubia/runtime/'.RuntimeBufferService::BUFFER_FILE));
    $hostname = (string) gethostname();
    $entry = bufferedOctane($app)->first();

    expect($raw)->not->toBe('')
        ->and($raw)->not->toContain(OCTANE_MARKER)
        ->and($raw)->not->toContain('example.test')
        ->and($raw)->not->toContain('/orders')
        ->and($raw)->not->toContain('203.0.113.7')
        ->and($raw)->not->toContain('/srv/')
        ->and($hostname === '' || ! str_contains($raw, $hostname))->toBeTrue()
        ->and(array_keys($entry))->toEqualCanonicalizing([
            'type', 'bucket', 'sampleRate', 'server', 'octaneServer', 'workerType', 'count', 'errorCount',
            'workerStarts', 'workerStops', 'requests', 'tasks', 'ticks', 'totalRequestsAtStop', 'maxRequestsAtStop', 'maxMb', 'maxGrowthMb',
        ])
        ->and(array_filter($entry, fn (mixed $value): bool => $value === getmypid()))->toBe([]);
});

it('records nothing at sample rate 0', function () {
    bootOctaneRuntime($this->app, ['octane' => ['enabled' => true, 'sampleRate' => 0]]);
    $app = $this->app;

    Event::dispatch(new WorkerStarting($app));
    octaneHandleRequest($app);
    Event::dispatch(new WorkerStopping($app));

    expect(bufferedOctane($app))->toHaveCount(0);
});

it('never lets an exception inside the Octane listeners reach the worker', function () {
    bootOctaneRuntime($this->app);
    $app = $this->app;
    $app->bind('Laravel\Octane\Contracts\Client', function (): never {
        throw new RuntimeException(OCTANE_MARKER);
    });

    Event::dispatch(new WorkerStarting($app));
    octaneHandleRequest($app);
    Event::dispatch(new TickReceived($app, $app));
    Event::dispatch(new WorkerErrorOccurred(new RuntimeException(OCTANE_MARKER), $app));
    Event::dispatch(new WorkerStopping($app));

    expect(bufferedOctane($app))->toHaveCount(0);
});

/**
 * `laravel-octane-config` on the booted test app.
 */
function octaneConfigResult(bool $isBooted = true): CheckResultData
{
    $snapshot = new ProjectSnapshotData(null, null, [], [], null);

    if ($isBooted) {
        $snapshot = $snapshot->withLaravelPosture((new LaravelPostureService)->collect(app(), checkIds: ['laravel-octane-config']));
    }

    return (new LaravelOctaneConfigCheck)->run($snapshot, ExcubiaConfigurationData::defaults());
}

describe('laravel-octane-config', function () {
    it('is skipped without a posture, and when laravel/octane is not installed', function () {
        expect(octaneConfigResult(isBooted: false)->status)->toBe(FindingStatusEnum::Skipped)
            ->and(octaneConfigResult()->status)->toBe(FindingStatusEnum::Skipped)
            ->and(octaneConfigResult()->findings[0]->message)->toBe('laravel/octane is not installed.');
    });

    it('reports the server type at info, and warns in production when max_requests is unset or 0', function (string $environment, mixed $maxRequests, ?SeverityEnum $warning) {
        $this->app['env'] = $environment;
        config(['octane' => array_filter(['server' => 'frankenphp', 'max_requests' => $maxRequests], fn (mixed $value): bool => $value !== null)]);

        $result = octaneConfigResult();
        $info = $result->findings[0];

        expect($info->status)->toBe(FindingStatusEnum::Pass)
            ->and($info->severity)->toBe(SeverityEnum::Info)
            ->and($info->subject)->toBe('octane.server')
            ->and($info->details)->toBe(['server' => 'frankenphp', 'maxRequests' => is_numeric($maxRequests) ? (int) $maxRequests : null])
            ->and($result->findings)->toHaveCount($warning === null ? 1 : 2);

        if ($warning !== null) {
            expect($result->status)->toBe(FindingStatusEnum::Warn)
                ->and($result->findings[1]->subject)->toBe('octane.max_requests')
                ->and($result->findings[1]->severity)->toBe($warning);
        }
    })->with([
        'production, unset' => ['production', null, SeverityEnum::Low],
        'production, 0' => ['production', 0, SeverityEnum::Medium],
        'production, "0" from the environment' => ['production', '0', SeverityEnum::Medium],
        'production, 500' => ['production', 500, null],
        'local, 0' => ['local', 0, null],
    ]);

    it('names a server it does not know unknown, and never reads other Octane settings', function () {
        config(['octane' => ['server' => OCTANE_MARKER, 'max_requests' => 500, 'cache' => ['rows' => 1000], 'swoole' => ['options' => ['log_file' => '/srv/'.OCTANE_MARKER]]]]);

        $result = octaneConfigResult();

        expect($result->findings[0]->details)->toBe(['server' => 'unknown', 'maxRequests' => 500])
            ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain(OCTANE_MARKER);
    });
});
