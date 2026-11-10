<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use JsonSchema\Validator;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Laravel\ExceptionCollectorService;
use Qiick\Excubia\Laravel\ExceptionPayloadService;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Services\RuntimeBufferService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Qiick\Excubia\Tests\Support\FakeSizeLimitedApp;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

const EXCEPTIONS_ENDPOINT = 'https://excubia.example.test/api/exceptions';
const EXCEPTIONS_RUNTIME_ENDPOINT = 'https://excubia.example.test/api/runtime';

final class ExceptionCaptureTestJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('Job failed for customer 98765432');
    }
}

final class ExceptionCaptureIgnoredException extends RuntimeException {}

/**
 * Wraps the app's handler and forwards report() and reportable(), as
 * nunomaduro/collision does in console processes (#241).
 */
final class ExceptionCaptureDecoratingHandler implements ExceptionHandler
{
    public function __construct(private readonly ExceptionHandler $handler) {}

    public function report(Throwable $e): void
    {
        $this->handler->report($e);
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->handler->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->handler->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->handler->renderForConsole($output, $e);
    }

    public function reportable(callable $reportUsing): mixed
    {
        return $this->handler->reportable($reportUsing);
    }
}

/**
 * A handler that accepts no reportable() callback.
 */
final class ExceptionCaptureClosedHandler implements ExceptionHandler
{
    public function report(Throwable $e): void {}

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    public function render($request, Throwable $e)
    {
        throw $e;
    }

    public function renderForConsole($output, Throwable $e): void {}
}

/**
 * Point the app at a temporary project and storage with this excubia.json,
 * then boot the provider again so it reads it.
 *
 * @param  array<string, mixed>|null  $document  null for no excubia.json
 */
function bootExceptionProject(Application $app, ?array $document = ['exceptions' => ['enabled' => true]]): string
{
    $project = temporaryProject($document === null ? [] : ['excubia.json' => json_encode($document, JSON_THROW_ON_ERROR)]);
    $app->setBasePath($project);
    $app->useStoragePath(temporaryProject([]));
    $app->register(ExcubiaServiceProvider::class, true);

    return $project;
}

/**
 * The exception groups in the local buffer, merged as the push merges them.
 *
 * @return list<array<string, mixed>>
 */
function bufferedExceptions(Application $app): array
{
    $path = $app->storagePath('excubia/exceptions/'.RuntimeBufferService::BUFFER_FILE);

    return ExceptionPayloadService::merge((new RuntimeBufferService(dirname($path), 1048576))->readEntries($path));
}

function rawExceptionBuffer(Application $app): string
{
    $path = $app->storagePath('excubia/exceptions/'.RuntimeBufferService::BUFFER_FILE);

    return is_file($path) ? (string) file_get_contents($path) : '';
}

function reportCallbackCount(Application $app): int
{
    $handler = $app->make(ExceptionHandler::class);

    if ($handler instanceof ExceptionCaptureDecoratingHandler) {
        $handler = (new ReflectionProperty($handler, 'handler'))->getValue($handler);
    }

    return count((new ReflectionProperty($handler, 'reportCallbacks'))->getValue($handler));
}

/**
 * @return array<string, int>
 */
function exceptionListenerCounts(): array
{
    $counts = [];

    foreach ([JobExceptionOccurred::class, CommandStarting::class, Looping::class] as $event) {
        $counts[$event] = count(Event::getListeners($event));
    }

    return $counts;
}

function hasPushRuntimeSchedule(Application $app): bool
{
    foreach ($app->make(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->command, 'excubia:push-runtime')) {
            return true;
        }
    }

    return false;
}

/**
 * @param  array<string, mixed>  $payload
 * @return list<string>
 */
function exceptionSchemaErrors(array $payload): array
{
    $validator = new Validator;
    $document = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $validator->validate($document, (object) ['$ref' => 'file://'.dirname(__DIR__, 2).'/docs/exception-schema.json']);

    return array_map(fn (array $error): string => $error['property'].': '.$error['message'], $validator->getErrors());
}

beforeEach(function () {
    putenv('EXCUBIA_APP_URL=https://excubia.example.test/');
    putenv('EXCUBIA_APP_TOKEN=exceptions-token-secret');
});

afterEach(function () {
    putenv('EXCUBIA_APP_URL');
    putenv('EXCUBIA_APP_TOKEN');
});

it('registers nothing on the handler when exception capture is disabled', function (?array $document) {
    $callbacks = reportCallbackCount($this->app);
    $listeners = exceptionListenerCounts();
    bootExceptionProject($this->app, $document);

    expect(reportCallbackCount($this->app))->toBe($callbacks)
        ->and(exceptionListenerCounts())->toBe($listeners)
        ->and($this->app->bound(ExceptionCollectorService::class))->toBeFalse();

    report(new RuntimeException('not captured'));
    $this->app->terminate();

    expect(rawExceptionBuffer($this->app))->toBe('');
})->with([
    'no excubia.json' => [null],
    'exceptions.enabled false' => [['exceptions' => ['enabled' => false]]],
    'only runtime enabled' => [['runtime' => ['enabled' => true]]],
]);

it('registers one reportable callback and the schedule when enabled, with runtime monitoring off', function () {
    $callbacks = reportCallbackCount($this->app);
    bootExceptionProject($this->app);

    expect(reportCallbackCount($this->app))->toBe($callbacks + 1)
        ->and($this->app->bound(ExceptionCollectorService::class))->toBeTrue()
        ->and(hasPushRuntimeSchedule($this->app))->toBeTrue();
});

it('captures a request exception with its route, masked, grouped and counted', function () {
    bootExceptionProject($this->app);
    Route::post('/orders/{order}', function (Request $request) {
        throw new RuntimeException('Order 1234567 failed for mario.rossi@example.test');
    })->name('orders.update');
    $user = new User;
    $user->forceFill(['id' => 42, 'email' => 'user-email-secret@example.test']);

    foreach ([1, 2] as $attempt) {
        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->withUnencryptedCookie('session-cookie', 'cookie-secret')
            ->withHeaders(['X-Api-Key' => 'header-secret'])
            ->post('/orders/url-secret?query=query-secret', ['password' => 'body-secret'])
            ->assertStatus(500);
    }

    $groups = bufferedExceptions($this->app);
    $raw = rawExceptionBuffer($this->app);

    expect($groups)->toHaveCount(1)
        ->and($groups[0])->toMatchArray([
            'class' => RuntimeException::class,
            'message' => 'Order [number] failed for [email]',
            'context' => ['kind' => 'request', 'name' => 'orders.update'],
            'environment' => 'testing',
            'count' => 2,
        ])
        ->and($groups[0]['firstSeen'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and(exceptionSchemaErrors(['groups' => $groups]))->toBe([]);

    foreach (['1234567', 'mario.rossi', 'body-secret', 'header-secret', 'cookie-secret', '203.0.113.77', 'user-email-secret', 'url-secret', 'query-secret', '"args"', $this->app->basePath(), dirname(__DIR__, 2)] as $secret) {
        expect($raw)->not->toContain($secret);
    }
});

it('leaves the host app reporting untouched', function () {
    bootExceptionProject($this->app);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message;
    });
    $hostCallbackCalls = 0;
    $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $exception) use (&$hostCallbackCalls): void {
        $hostCallbackCalls++;
    });

    report(new RuntimeException('host app sees mario@example.test'));
    $this->app->terminate();

    expect($hostCallbackCalls)->toBe(1)
        ->and($logged)->toContain('host app sees mario@example.test')
        ->and(bufferedExceptions($this->app)[0]['message'])->toBe('host app sees [email]');
});

it('captures the job class as context', function () {
    bootExceptionProject($this->app);
    config(['queue.default' => 'sync']);

    try {
        ExceptionCaptureTestJob::dispatch();
    } catch (RuntimeException $exception) {
        // The sync queue rethrows; a worker reports it the same way.
        report($exception);
    }

    $this->app->terminate();
    $group = bufferedExceptions($this->app)[0];

    expect($group['context'])->toBe(['kind' => 'job', 'name' => ExceptionCaptureTestJob::class])
        ->and($group['message'])->toBe('Job failed for customer [number]');
});

it('captures a failed queued job through a decorating handler, with the job as context, registered once', function () {
    $this->app->extend(ExceptionHandler::class, fn (ExceptionHandler $handler): ExceptionHandler => new ExceptionCaptureDecoratingHandler($handler));
    $callbacks = reportCallbackCount($this->app);
    bootExceptionProject($this->app);
    $handler = $this->app->make(ExceptionHandler::class);
    $this->app->make(ExceptionCollectorService::class)->attach($handler);

    expect($handler)->toBeInstanceOf(ExceptionCaptureDecoratingHandler::class)
        ->and(reportCallbackCount($this->app))->toBe($callbacks + 1)
        ->and($this->app->make(ExceptionCollectorService::class)->isAttached())->toBeTrue();

    // A queue worker reports a failed job through the handler it was given.
    $worker = new Worker($this->app->make('queue'), $this->app->make('events'), $handler, fn (): bool => false);
    $payload = json_encode([
        'uuid' => 'job-uuid',
        'displayName' => ExceptionCaptureTestJob::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 1,
        'maxExceptions' => null,
        'failOnTimeout' => false,
        'backoff' => null,
        'timeout' => null,
        'retryUntil' => null,
        'data' => ['commandName' => ExceptionCaptureTestJob::class, 'command' => serialize(new ExceptionCaptureTestJob)],
    ], JSON_THROW_ON_ERROR);
    (new ReflectionMethod($worker, 'runJob'))->invoke($worker, new SyncJob($this->app, $payload, 'sync', 'default'), 'sync', new WorkerOptions(maxTries: 1));
    event(new Looping('sync', 'default'));

    $groups = bufferedExceptions($this->app);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['context'])->toBe(['kind' => 'job', 'name' => ExceptionCaptureTestJob::class])
        ->and($groups[0]['count'])->toBe(1);
});

it('warns in excubia:check when exception capture is on but no handler accepts the callback', function (bool $isClosedHandler) {
    if ($isClosedHandler) {
        $this->app->instance(ExceptionHandler::class, new ExceptionCaptureClosedHandler);
    }

    bootExceptionProject($this->app);
    $warning = 'Exception capture is enabled (exceptions.enabled), but the exception handler does not accept a reportable() callback';

    $command = $this->artisan('excubia:check', ['--only' => 'php-eol', '--offline' => true, '--fail-on' => 'none', '--no-banner' => true]);

    if ($isClosedHandler) {
        $command->expectsOutputToContain($warning);
    } else {
        $command->doesntExpectOutputToContain($warning);
    }

    $command->run();
    expect($this->app->make(ExceptionCollectorService::class)->isAttached())->toBe(! $isClosedHandler);
})->with([
    'closed handler' => [true],
    'laravel handler' => [false],
]);

it('captures the Artisan command name as context, also for an uncaught exception reported after the command ended', function () {
    bootExceptionProject($this->app);
    $input = new ArrayInput(['--token' => 'option-secret']);
    // The console kernel fires these outside unit tests.
    event(new CommandStarting('shop:sync', $input, new NullOutput));
    report(new RuntimeException('sync failed'));
    event(new CommandFinished('shop:sync', $input, new NullOutput, 0));
    report(new LogicException('after a successful command'));
    event(new CommandStarting('shop:import', $input, new NullOutput));
    event(new CommandFinished('shop:import', $input, new NullOutput, 1));
    report(new DomainException('uncaught in the command'));
    $this->app->terminate();

    $contexts = array_column(bufferedExceptions($this->app), 'context', 'class');

    expect($contexts)->toBe([
        RuntimeException::class => ['kind' => 'command', 'name' => 'shop:sync'],
        LogicException::class => ['kind' => 'unknown', 'name' => 'unknown'],
        DomainException::class => ['kind' => 'command', 'name' => 'shop:import'],
    ])
        ->and(rawExceptionBuffer($this->app))->not->toContain('option-secret');
});

it('uses unknown as context outside a request, job or command', function () {
    bootExceptionProject($this->app);

    report(new RuntimeException('somewhere'));
    $this->app->terminate();

    expect(bufferedExceptions($this->app)[0]['context'])->toBe(['kind' => 'unknown', 'name' => 'unknown']);
});

it('skips ignored classes and their subclasses and applies the user mask patterns', function () {
    bootExceptionProject($this->app, ['exceptions' => ['enabled' => true, 'ignore' => [RuntimeException::class], 'maskPatterns' => ['/ORD-[0-9]+/']]]);

    report(new ExceptionCaptureIgnoredException('ignored'));
    report(new LogicException('Order ORD-77 is invalid'));
    $this->app->terminate();

    $groups = bufferedExceptions($this->app);

    expect($groups)->toHaveCount(1)
        ->and($groups[0])->toMatchArray(['class' => LogicException::class, 'message' => 'Order [masked] is invalid']);
});

it('never lets an exception inside the capture reach the host app', function () {
    bootExceptionProject($this->app);
    // A regular file where the buffer directory should be: every write fails.
    $blocked = temporaryProject(['storage' => 'not a directory']).'/storage';
    $this->app->useStoragePath($blocked);
    $this->app->register(ExcubiaServiceProvider::class, true);
    Route::get('/broken', fn () => throw new RuntimeException('broken'))->name('broken');

    $this->get('/broken')->assertStatus(500);
    report(new RuntimeException('still reported'));
    $this->app->terminate();

    $collector = $this->app->make(ExceptionCollectorService::class);
    $request = $this->app->make('request');
    $this->app->bind('request', fn () => throw new RuntimeException('container broken'));
    $collector->capture(new RuntimeException('capture fails'));
    $this->app->instance('request', $request);
    $collector->flush();

    expect(is_file($blocked))->toBeTrue();
});

it('flushes when a queue worker loops and before too many groups are pending', function () {
    bootExceptionProject($this->app);
    $collector = $this->app->make(ExceptionCollectorService::class);

    report(new RuntimeException('before loop'));
    event(new Looping('sync', 'default'));

    expect(bufferedExceptions($this->app))->toHaveCount(1);

    for ($i = 0; $i < ExceptionCollectorService::MAX_PENDING_GROUPS; $i++) {
        $collector->startCommand(new CommandStarting('command:'.$i, new ArrayInput([]), new NullOutput));
        $collector->capture(new RuntimeException('many'));
        $collector->finishCommand(0);
    }

    expect(bufferedExceptions($this->app))->toHaveCount(1 + ExceptionCollectorService::MAX_PENDING_GROUPS);
});

it('sends exception batches to /api/exceptions with runtime monitoring off and deletes them after 2xx', function () {
    bootExceptionProject($this->app);
    $client = new FakeHttpClient([EXCEPTIONS_ENDPOINT => ['stored' => true]]);
    $this->app->instance(HttpClientContract::class, $client);
    report(new RuntimeException('first'));
    report(new RuntimeException('first'));
    $this->app->terminate();

    $this->artisan('excubia:push-runtime')
        ->expectsOutputToContain('Sent 1 exception batch to excubia-app.')
        ->doesntExpectOutputToContain('runtime')
        ->assertExitCode(0);

    $payload = $client->postedPayloads[0];

    expect($client->calls)->toBe(1)
        ->and($client->lastPostUrl)->toBe(EXCEPTIONS_ENDPOINT)
        ->and($client->lastHeaders)->toBe(['Authorization' => 'Bearer exceptions-token-secret'])
        ->and(array_keys($payload))->toBe(['protocolVersion', 'type', 'batchId', 'sentAt', 'exceptions'])
        ->and($payload['type'])->toBe('exceptions')
        ->and($payload['exceptions']['groups'])->toHaveCount(1)
        ->and($payload['exceptions']['groups'][0]['count'])->toBe(2)
        ->and(exceptionSchemaErrors($payload['exceptions']))->toBe([])
        ->and(glob($this->app->storagePath('excubia/exceptions/batch-*.ndjson')))->toBe([]);

    $this->artisan('excubia:push-runtime')
        ->expectsOutputToContain('No exception data to send.')
        ->assertExitCode(0);
});

it('splits an exception batch the app answers 413 to, so every group is stored once', function () {
    bootExceptionProject($this->app);

    foreach ([RuntimeException::class, LogicException::class, InvalidArgumentException::class, DomainException::class, RangeException::class, OverflowException::class] as $class) {
        report(new $class('too large together'));
    }

    $this->app->terminate();
    $fingerprints = array_column(bufferedExceptions($this->app), 'fingerprint');
    $lines = array_filter(explode("\n", rawExceptionBuffer($this->app)));
    $limit = max(array_map(RuntimeBufferService::encodedBytes(...), $lines)) + 1024;
    $app = new FakeSizeLimitedApp($limit);
    $this->app->instance(HttpClientContract::class, $app);

    $this->artisan('excubia:push-runtime')
        ->expectsOutputToContain('exception batches to excubia-app.')
        ->assertExitCode(0);

    $stored = array_merge(...array_map(fn (array $payload): array => array_column($payload['exceptions']['groups'], 'fingerprint'), array_values($app->stored)));

    expect(array_sum(array_map(RuntimeBufferService::encodedBytes(...), $lines)))->toBeGreaterThan($limit)
        ->and($fingerprints)->toHaveCount(6)
        ->and($app->attempts[0]['status'])->toBe(413)
        ->and($stored)->toEqualCanonicalizing($fingerprints)
        ->and(glob($this->app->storagePath('excubia/exceptions/batch-*.ndjson')))->toBe([]);
});

it('adds the release to the exception batch without changing the group fingerprint', function () {
    $fingerprints = [];
    $payloads = [];

    foreach ([null, 'v7.0.0'] as $release) {
        bootExceptionProject($this->app);
        $client = new FakeHttpClient([EXCEPTIONS_ENDPOINT => ['stored' => true]]);
        $this->app->instance(HttpClientContract::class, $client);
        report(new RuntimeException('same failure'));
        $this->app->terminate();
        putenv($release === null ? 'EXCUBIA_RELEASE' : 'EXCUBIA_RELEASE='.$release);

        try {
            $this->artisan('excubia:push-runtime')->assertExitCode(0);
        } finally {
            putenv('EXCUBIA_RELEASE');
        }

        $payloads[] = $client->postedPayloads[0]['exceptions'];
        $fingerprints[] = $client->postedPayloads[0]['exceptions']['groups'][0]['fingerprint'];
    }

    expect($payloads[0])->not->toHaveKey('release')
        ->and(array_keys($payloads[1]))->toBe(['release', 'groups'])
        ->and($payloads[1]['release'])->toBe('v7.0.0')
        ->and($fingerprints[1])->toBe($fingerprints[0])
        ->and(exceptionSchemaErrors($payloads[1]))->toBe([]);
});

it('keeps a rejected exception batch and resends it with the same batchId', function () {
    bootExceptionProject($this->app);
    $failing = new FakeHttpClient([], [EXCEPTIONS_ENDPOINT => new DataSourceUnavailableException('http', 'The data source returned HTTP 503.', httpStatus: 503)]);
    $this->app->instance(HttpClientContract::class, $failing);
    report(new RuntimeException('kept'));
    $this->app->terminate();

    $this->artisan('excubia:push-runtime')
        ->expectsOutputToContain('did not accept the exception batch (HTTP 503)')
        ->expectsOutputToContain('The exception data is kept for the next run.')
        ->assertExitCode(3);

    $working = new FakeHttpClient([EXCEPTIONS_ENDPOINT => ['stored' => true]]);
    $this->app->instance(HttpClientContract::class, $working);

    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    expect($working->postedPayloads[0]['batchId'])->toBe($failing->postedPayloads[0]['batchId']);
});

it('still sends exceptions when the runtime batch fails, and exits 3', function () {
    bootExceptionProject($this->app, ['runtime' => ['enabled' => true], 'exceptions' => ['enabled' => true]]);
    $client = new FakeHttpClient([EXCEPTIONS_ENDPOINT => ['stored' => true]], [EXCEPTIONS_RUNTIME_ENDPOINT => new DataSourceUnavailableException('http', 'down')]);
    $this->app->instance(HttpClientContract::class, $client);
    Route::get('/boom', fn () => throw new RuntimeException('boom'))->name('boom');
    $this->get('/boom')->assertStatus(500);

    $this->artisan('excubia:push-runtime')
        ->expectsOutputToContain('The runtime data is kept for the next run.')
        ->expectsOutputToContain('Sent 1 exception batch to excubia-app.')
        ->assertExitCode(3);

    expect(array_column($client->postedPayloads, 'type'))->toBe(['runtime', 'exceptions']);
});
