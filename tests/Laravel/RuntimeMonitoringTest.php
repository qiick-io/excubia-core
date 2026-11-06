<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Qiick\Excubia\Data\RuntimeConfigurationData;
use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\RuntimeCollectorService;
use Qiick\Excubia\Services\RuntimeBufferService;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

final class RuntimeMonitoringTestJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public bool $shouldFail = false) {}

    public function handle(): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException('job-exception-secret');
        }
    }
}

/**
 * Point the app at a temporary project and storage with this `runtime` key,
 * then boot the provider again so it reads that `excubia.json`.
 *
 * @param  array<string, mixed>|null  $runtime  null for no excubia.json
 */
function bootRuntimeProject(Application $app, ?array $runtime): string
{
    $files = $runtime === null ? [] : ['excubia.json' => json_encode(['runtime' => $runtime], JSON_THROW_ON_ERROR)];
    $project = temporaryProject($files);
    $app->setBasePath($project);
    $app->useStoragePath(temporaryProject([]));
    $app->register(ExcubiaServiceProvider::class, true);

    return $project;
}

/**
 * The aggregates written to the local buffer so far.
 *
 * @return list<array<string, mixed>>
 */
function bufferedRuntime(Application $app): array
{
    $path = $app->storagePath('excubia/runtime/'.RuntimeBufferService::BUFFER_FILE);

    if (! is_file($path)) {
        return [];
    }

    return array_map(
        fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", (string) file_get_contents($path)))),
    );
}

function rawBuffer(Application $app): string
{
    $path = $app->storagePath('excubia/runtime/'.RuntimeBufferService::BUFFER_FILE);

    return is_file($path) ? (string) file_get_contents($path) : '';
}

function hasRuntimeSchedule(Application $app): bool
{
    foreach ($app->make(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->command, 'excubia:push-runtime')) {
            return true;
        }
    }

    return false;
}

const RUNTIME_EVENTS = [
    RequestHandled::class,
    QueryExecuted::class,
    JobProcessing::class,
    JobProcessed::class,
    JobFailed::class,
    ScheduledTaskFinished::class,
    ScheduledTaskFailed::class,
    ScheduledTaskSkipped::class,
    ResponseReceived::class,
];

/**
 * Listener count per runtime event, framework listeners included.
 *
 * @return array<class-string, int>
 */
function runtimeListenerCounts(): array
{
    $counts = [];

    foreach (RUNTIME_EVENTS as $event) {
        $counts[$event] = count(Event::getListeners($event));
    }

    return $counts;
}

it('registers no listener, collector or schedule when runtime monitoring is disabled', function (?array $runtime) {
    $before = runtimeListenerCounts();
    bootRuntimeProject($this->app, $runtime);

    expect(runtimeListenerCounts())->toBe($before);

    expect($this->app->bound(RuntimeCollectorService::class))->toBeFalse()
        ->and(hasRuntimeSchedule($this->app))->toBeFalse();

    Route::get('/plain', fn () => 'ok')->name('plain');
    $this->get('/plain')->assertOk();

    expect(bufferedRuntime($this->app))->toBe([]);
})->with([
    'no excubia.json' => [null],
    'runtime.enabled false' => [['enabled' => false, 'requests' => ['enabled' => true]]],
]);

it('registers the listeners and the every-minute schedule when enabled', function () {
    $before = runtimeListenerCounts();
    bootRuntimeProject($this->app, ['enabled' => true]);
    $after = runtimeListenerCounts();

    foreach (RUNTIME_EVENTS as $event) {
        expect($after[$event])->toBe($before[$event] + 1);
    }

    $event = collect($this->app->make(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'excubia:push-runtime'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});

it('registers only the types that are switched on', function () {
    $before = runtimeListenerCounts();
    bootRuntimeProject($this->app, ['enabled' => true, 'slowQueries' => ['enabled' => false], 'jobs' => ['enabled' => false]]);
    $after = runtimeListenerCounts();

    expect($after[RequestHandled::class])->toBe($before[RequestHandled::class] + 1)
        ->and($after[QueryExecuted::class])->toBe($before[QueryExecuted::class])
        ->and($after[JobProcessed::class])->toBe($before[JobProcessed::class])
        ->and($after[ScheduledTaskFinished::class])->toBe($before[ScheduledTaskFinished::class] + 1);
});

it('aggregates requests by route name, method and status class', function () {
    bootRuntimeProject($this->app, ['enabled' => true]);
    Route::get('/users/{id}', fn () => 'ok')->name('users.show');
    Route::post('/broken', fn () => abort(500));

    $this->get('/users/1')->assertOk();
    $this->get('/users/2')->assertOk();
    $this->post('/broken')->assertStatus(500);

    $aggregates = RuntimeBufferService::merge(bufferedRuntime($this->app));
    $named = collect($aggregates)->firstWhere('route', 'users.show');
    $unnamed = collect($aggregates)->firstWhere('route', 'unnamed');

    expect($aggregates)->toHaveCount(2)
        ->and($named)->toMatchArray(['type' => 'request', 'method' => 'GET', 'statusClass' => '2xx', 'count' => 2, 'errorCount' => 0, 'sampleRate' => 1.0])
        ->and($named['bucket'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00Z$/')
        ->and($named['totalMs'])->toBeGreaterThanOrEqual($named['maxMs'])
        ->and($unnamed)->toMatchArray(['method' => 'POST', 'statusClass' => '5xx', 'count' => 1, 'errorCount' => 1]);
});

it('never writes request bodies, headers, cookies, IP addresses, users or the URL to the buffer', function () {
    bootRuntimeProject($this->app, ['enabled' => true]);
    Route::post('/profile/{token}', fn (Request $request) => response('ok'))->name('profile.update');
    $user = new User;
    $user->forceFill(['id' => 42, 'email' => 'user-email-secret@example.test']);

    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
        ->withUnencryptedCookie('session-cookie', 'cookie-secret')
        ->withHeaders(['X-Api-Key' => 'header-secret'])
        ->post('/profile/url-secret?query=query-secret', ['password' => 'body-secret'])
        ->assertOk();

    $raw = rawBuffer($this->app);

    expect($raw)->toContain('profile.update');

    foreach (['body-secret', 'header-secret', 'cookie-secret', '203.0.113.77', 'user-email-secret', 'url-secret', 'query-secret', '"42"', $this->app->basePath()] as $secret) {
        expect($raw)->not->toContain($secret);
    }
});

it('records slow queries without bindings or inline literals', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'slowQueries' => ['thresholdMs' => 0]]);

    DB::select("select ? as binding_column, 'literal-secret' as literal_column", ['binding-secret']);
    $this->app->terminate();

    $raw = rawBuffer($this->app);
    $query = collect(bufferedRuntime($this->app))->firstWhere('type', 'slowQuery');

    expect($query)->toMatchArray([
        'sql' => 'select ? as binding_column, ? as literal_column',
        'connection' => DB::getDefaultConnection(),
        'count' => 1,
        'errorCount' => 0,
    ])
        ->and($raw)->not->toContain('binding-secret')
        ->and($raw)->not->toContain('literal-secret');
});

it('ignores queries below the threshold', function () {
    bootRuntimeProject($this->app, ['enabled' => true]);

    DB::select('select 1');
    $this->app->terminate();

    expect(collect(bufferedRuntime($this->app))->where('type', 'slowQuery'))->toBeEmpty();
});

it('masks quoted literals and truncates long SQL', function () {
    expect(RuntimeCollectorService::maskSql("update users set name = 'O''Brien', note = 'a\\'b' where id = ?"))
        ->toBe('update users set name = ?, note = ? where id = ?')
        ->and(mb_strlen(RuntimeCollectorService::maskSql(str_repeat('x', 5000))))->toBe(RuntimeCollectorService::MAX_SQL_LENGTH);
});

it('masks inline numeric, hex and dollar-quoted literals', function (string $sql, string $expected) {
    expect(RuntimeCollectorService::maskSql($sql))->toBe($expected);
})->with([
    'numbers' => ['select * from orders where user_id = 48213 and total > 19.99 and id in (1, 2) limit 10', 'select * from orders where user_id = ? and total > ? and id in (?, ?) limit ?'],
    'hex' => ["select * from tokens where token = 0x5f3a9c or hash = X'0aFF' or hash = x''", 'select * from tokens where token = ? or hash = ? or hash = ?'],
    'dollar-quoted' => ['select * from notes where note = $$secret$$ or body = $body$it\'s 42 $1$body$', 'select * from notes where note = ? or body = ?'],
    'placeholders and identifiers' => ['select t1.col_2, "Column 3" from t1 join t2 on t1.id = t2.t1_id where a = $1 and b = ? and c = :name1', 'select t1.col_2, "Column 3" from t1 join t2 on t1.id = t2.t1_id where a = $1 and b = ? and c = :name1'],
]);

it('aggregates processed and failed jobs by class, queue and outcome without the exception message', function () {
    bootRuntimeProject($this->app, ['enabled' => true]);
    config(['queue.default' => 'sync']);

    RuntimeMonitoringTestJob::dispatch();
    RuntimeMonitoringTestJob::dispatch();

    try {
        RuntimeMonitoringTestJob::dispatch(true);
    } catch (RuntimeException) {
        // The sync queue rethrows the job's own exception to the caller.
    }

    $jobs = collect(RuntimeBufferService::merge(bufferedRuntime($this->app)))->where('type', 'job');
    $processed = $jobs->firstWhere('outcome', 'processed');
    $failed = $jobs->firstWhere('outcome', 'failed');

    expect($processed)->toMatchArray(['job' => RuntimeMonitoringTestJob::class, 'connection' => 'sync', 'count' => 2, 'errorCount' => 0])
        ->and($processed)->toHaveKey('queue')
        ->and($failed)->toMatchArray(['job' => RuntimeMonitoringTestJob::class, 'count' => 1, 'errorCount' => 1])
        ->and(rawBuffer($this->app))->not->toContain('job-exception-secret');
});

it('aggregates scheduled tasks by name and outcome, once per failed command, without arguments or absolute paths', function () {
    $project = bootRuntimeProject($this->app, ['enabled' => true]);
    $schedule = $this->app->make(Schedule::class);
    $command = $schedule->command('inspire');
    $failing = $schedule->command('migrate --force');
    $closure = $schedule->call(fn () => null);
    $described = $schedule->call(fn () => null)->description('nightly cleanup');
    $script = $schedule->exec('/usr/local/bin/backup.sh --target='.$project.'/storage/backups');

    event(new ScheduledTaskFinished($command, 0.25));
    $failing->exitCode = 1;
    event(new ScheduledTaskFinished($failing, 1.5));
    event(new ScheduledTaskFailed($failing, new RuntimeException('task-exception-secret')));
    event(new ScheduledTaskFailed($closure, new RuntimeException('closure-exception-secret')));
    event(new ScheduledTaskSkipped($described));
    event(new ScheduledTaskFinished($script, 0.0));

    $tasks = collect(RuntimeBufferService::merge(bufferedRuntime($this->app)))->where('type', 'scheduledTask')->keyBy('task');
    $raw = rawBuffer($this->app);

    expect($tasks->keys()->all())->toEqualCanonicalizing(['artisan inspire', 'artisan migrate', 'Closure', 'nightly cleanup', 'backup.sh'])
        ->and($tasks['artisan inspire'])->toMatchArray(['outcome' => 'finished', 'count' => 1, 'errorCount' => 0, 'totalMs' => 250.0])
        ->and($tasks['artisan migrate'])->toMatchArray(['outcome' => 'failed', 'count' => 1, 'errorCount' => 1, 'totalMs' => 1500.0])
        ->and($tasks['Closure'])->toMatchArray(['outcome' => 'failed', 'errorCount' => 1])
        ->and($tasks['nightly cleanup'])->toMatchArray(['outcome' => 'skipped', 'totalMs' => 0.0])
        ->and($raw)->not->toContain('exception-secret')
        ->and($raw)->not->toContain(PHP_BINARY)
        ->and($raw)->not->toContain($project)
        ->and($raw)->not->toContain('--force')
        ->and($raw)->not->toContain('storage/backups');
});

it('drops the arguments of scheduled commands and masks secrets in descriptions', function () {
    bootRuntimeProject($this->app, ['enabled' => true]);
    $schedule = $this->app->make(Schedule::class);
    $command = $schedule->command('backup:run', ['--password' => 'task-password-secret']);
    $script = $schedule->exec('/usr/bin/mysqldump --password=dump-password-secret shop');
    $described = $schedule->call(fn () => null)->description('sync with token=description-token-secret');

    event(new ScheduledTaskFinished($command, 0.1));
    event(new ScheduledTaskFinished($script, 0.1));
    event(new ScheduledTaskFinished($described, 0.1));

    $tasks = collect(RuntimeBufferService::merge(bufferedRuntime($this->app)))->where('type', 'scheduledTask')->pluck('task')->all();
    $raw = rawBuffer($this->app);

    expect($tasks)->toEqualCanonicalizing(['artisan backup:run', 'mysqldump', 'sync with token=[masked]']);

    foreach (['task-password-secret', 'dump-password-secret', 'description-token-secret', 'shop'] as $secret) {
        expect($raw)->not->toContain($secret);
    }
});

it('keeps no secret that the masks cannot recognise in scheduled command names', function () {
    bootRuntimeProject($this->app, ['enabled' => true]);
    $schedule = $this->app->make(Schedule::class);
    $tasks = [
        $schedule->command('tenant:sync hunter2-positional'),
        $schedule->exec('/usr/bin/mysqldump -phunter2-short shop'),
        $schedule->exec("'/opt/my tools/run report.sh' --key hunter2-quoted"),
        $schedule->exec('API_KEY=hunter2-env FLAG="a b" /srv/bin/sync.sh now'),
        $schedule->exec('C:\\tools\\backup.exe /p:hunter2-windows'),
    ];

    foreach ($tasks as $task) {
        event(new ScheduledTaskFinished($task, 0.1));
    }

    $names = collect(RuntimeBufferService::merge(bufferedRuntime($this->app)))->where('type', 'scheduledTask')->pluck('task')->all();

    expect($names)->toEqualCanonicalizing(['artisan tenant:sync', 'mysqldump', 'run report.sh', 'sync.sh', 'backup.exe'])
        ->and(rawBuffer($this->app))->not->toContain('hunter2');
});

it('aggregates outgoing HTTP by host and status class only', function () {
    bootRuntimeProject($this->app, ['enabled' => true]);
    Http::fake([
        'api.example.test/*' => Http::response(['ok' => true]),
        'down.example.test/*' => Http::response([], 503),
    ]);

    Http::withToken('outgoing-token-secret')->get('https://api.example.test/v1/users?api_key=query-secret');
    Http::get('https://API.example.test/v1/other');
    Http::post('https://down.example.test/hooks', ['payload' => 'payload-secret']);
    $this->app->terminate();

    $calls = collect(RuntimeBufferService::merge(bufferedRuntime($this->app)))->where('type', 'outgoingHttp')->keyBy('host');
    $raw = rawBuffer($this->app);

    expect($calls['api.example.test'])->toMatchArray(['statusClass' => '2xx', 'count' => 2, 'errorCount' => 0])
        ->and($calls['down.example.test'])->toMatchArray(['statusClass' => '5xx', 'count' => 1, 'errorCount' => 1]);

    foreach (['outgoing-token-secret', 'query-secret', 'payload-secret', '/v1/users', 'hooks'] as $secret) {
        expect($raw)->not->toContain($secret);
    }
});

it('records nothing for a type with sample rate 0', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'requests' => ['sampleRate' => 0]]);
    Route::get('/sampled', fn () => 'ok');

    $this->get('/sampled')->assertOk();

    expect(bufferedRuntime($this->app))->toBe([]);
});

it('records about the configured share of events and keeps the rate on each aggregate', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'scheduler' => ['sampleRate' => 0.5]]);
    $task = $this->app->make(Schedule::class)->command('inspire');
    mt_srand(75);

    for ($i = 0; $i < 400; $i++) {
        event(new ScheduledTaskSkipped($task));
    }

    $aggregate = RuntimeBufferService::merge(bufferedRuntime($this->app))[0];

    expect($aggregate['sampleRate'])->toBe(0.5)
        ->and($aggregate['count'])->toBeGreaterThan(140)
        ->and($aggregate['count'])->toBeLessThan(260);
});

it('never lets an exception inside the collection reach the host app', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'slowQueries' => ['thresholdMs' => 0]]);
    // A regular file where the buffer directory should be: every write fails.
    $blocked = temporaryProject(['storage' => 'not a directory']).'/storage';
    $this->app->useStoragePath($blocked);
    $this->app->register(ExcubiaServiceProvider::class, true);
    Route::get('/still-works', fn () => DB::select('select 1') ? 'ok' : 'ok')->name('still.works');
    config(['queue.default' => 'sync']);
    $task = $this->app->make(Schedule::class)->command('inspire');

    $this->get('/still-works')->assertOk()->assertSee('ok');
    RuntimeMonitoringTestJob::dispatch();
    event(new ScheduledTaskFinished($task, 0.1));
    $this->app->make(RuntimeCollectorService::class)->recordJob(new JobProcessed('sync', new stdClass));
    $this->app->terminate();

    expect(is_file($blocked))->toBeTrue();
});

it('flushes a long-running process before it holds too many aggregates', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'slowQueries' => ['thresholdMs' => 0]]);

    for ($i = 0; $i < RuntimeCollectorService::MAX_PENDING_AGGREGATES; $i++) {
        DB::select('select 1 as column_'.$i); // distinct identifiers: inline numbers are masked to one aggregate
    }

    expect(bufferedRuntime($this->app))->toHaveCount(RuntimeCollectorService::MAX_PENDING_AGGREGATES);
});

final class RuntimeMonitoringTestMail extends Mailable
{
    public function build(): self
    {
        return $this->subject('mail-subject-secret')->html('mail-body-secret');
    }
}

final class RuntimeMonitoringTestChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if ($notification instanceof RuntimeMonitoringTestNotification && $notification->shouldFail) {
            throw new RuntimeException('notification-exception-secret');
        }
    }
}

final class RuntimeMonitoringTestNotification extends Notification
{
    public function __construct(public bool $shouldFail = false) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [RuntimeMonitoringTestChannel::class];
    }
}

/**
 * Every runtime type switched on, with the given overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function allRuntimeTypes(array $overrides = []): array
{
    $runtime = ['enabled' => true];

    foreach (RuntimeTypeEnum::cases() as $type) {
        $runtime[$type->configurationKey()] = ['enabled' => true];
    }

    return array_replace_recursive($runtime, $overrides);
}

/**
 * The merged buffered aggregates of one type.
 *
 * @return Collection<int, array<string, mixed>>
 */
function bufferedOfType(Application $app, string $type): Collection
{
    return collect(RuntimeBufferService::merge(bufferedRuntime($app)))->where('type', $type)->values();
}

/**
 * Boot a project whose excubia.json is this whole document.
 *
 * @param  array<string, mixed>  $document
 */
function bootExcubiaDocument(Application $app, array $document): void
{
    $app->setBasePath(temporaryProject(['excubia.json' => json_encode($document, JSON_THROW_ON_ERROR)]));
    $app->useStoragePath(temporaryProject([]));
    $app->register(ExcubiaServiceProvider::class, true);
}

const BETA_TWO_EVENTS = [
    CacheHit::class,
    CacheMissed::class,
    KeyWritten::class,
    KeyForgotten::class,
    MessageSending::class,
    MessageSent::class,
    NotificationSent::class,
    NotificationFailed::class,
    MessageLogged::class,
];

/**
 * @return array<class-string, int>
 */
function betaTwoListenerCounts(): array
{
    $counts = [];

    foreach (BETA_TWO_EVENTS as $event) {
        $counts[$event] = count(Event::getListeners($event));
    }

    return $counts;
}

it('keeps the types added in 1.0.0-beta.2 off until each one is switched on', function () {
    $before = betaTwoListenerCounts();
    bootRuntimeProject($this->app, ['enabled' => true]);

    expect(betaTwoListenerCounts())->toBe($before);

    bootRuntimeProject($this->app, allRuntimeTypes());

    foreach (betaTwoListenerCounts() as $event => $count) {
        expect($count)->toBe($before[$event] + 1);
    }
});

it('records request peak memory by route name and method without the URL', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'requests' => ['enabled' => false], 'requestMemory' => ['enabled' => true]]);
    Route::get('/orders/{order}', fn () => 'ok')->name('orders.show');

    $this->get('/orders/memory-url-secret')->assertOk();
    $this->get('/orders/2')->assertOk();

    $memory = bufferedOfType($this->app, 'requestMemory');

    expect($memory)->toHaveCount(1)
        ->and($memory[0])->toMatchArray(['route' => 'orders.show', 'method' => 'GET', 'count' => 2, 'errorCount' => 0, 'sampleRate' => 1.0])
        ->and($memory[0])->not->toHaveKeys(['totalMs', 'maxMs', 'statusClass'])
        ->and($memory[0]['maxMb'])->toBeGreaterThan(0)
        ->and($memory[0]['totalMb'])->toBeGreaterThanOrEqual($memory[0]['maxMb'])
        ->and(bufferedOfType($this->app, 'request'))->toBeEmpty()
        ->and(rawBuffer($this->app))->not->toContain('memory-url-secret');
});

it('records job peak memory by class and queue, failed jobs included', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'jobs' => ['enabled' => false], 'jobMemory' => ['enabled' => true]]);
    config(['queue.default' => 'sync']);

    RuntimeMonitoringTestJob::dispatch();

    try {
        RuntimeMonitoringTestJob::dispatch(true);
    } catch (RuntimeException) {
        // The sync queue rethrows the job's own exception to the caller.
    }

    $memory = bufferedOfType($this->app, 'jobMemory');

    expect($memory)->toHaveCount(1)
        ->and($memory[0])->toMatchArray(['job' => RuntimeMonitoringTestJob::class, 'count' => 2, 'errorCount' => 0])
        ->and($memory[0])->toHaveKey('queue')
        ->and($memory[0]['maxMb'])->toBeGreaterThan(0)
        ->and(bufferedOfType($this->app, 'job'))->toBeEmpty()
        ->and(rawBuffer($this->app))->not->toContain('job-exception-secret');
});

it('keeps the request peak when a sync job resets the PHP peak', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'requestMemory' => ['enabled' => true], 'jobMemory' => ['enabled' => true]]);
    config(['queue.default' => 'sync']);
    Route::get('/heavy', function () {
        $filler = str_repeat('x', 16 * 1048576);
        $length = strlen($filler);
        unset($filler);
        RuntimeMonitoringTestJob::dispatch();

        return (string) $length;
    })->name('heavy');

    $this->get('/heavy')->assertOk();

    $request = bufferedOfType($this->app, 'requestMemory')[0];
    $job = bufferedOfType($this->app, 'jobMemory')[0];

    expect($request['maxMb'])->toBeGreaterThan($job['maxMb'] + 8.0);
});

/**
 * A job with this payload, as a worker would see it.
 *
 * @param  array<string, mixed>  $payload
 */
function waitingJob(Application $app, array $payload, int $attempts = 1): SyncJob
{
    $json = json_encode(['displayName' => RuntimeMonitoringTestJob::class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => RuntimeMonitoringTestJob::class], ...$payload], JSON_THROW_ON_ERROR);

    return new class($app, $json, 'redis', 'emails', $attempts) extends SyncJob
    {
        public function __construct(Container $container, string $payload, string $connectionName, string $queue, private readonly int $attemptCount)
        {
            parent::__construct($container, $payload, $connectionName, $queue);
        }

        public function attempts(): int
        {
            return $this->attemptCount;
        }
    };
}

it('records the queue wait from the push time without the delay, first attempts only', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'queueWait' => ['enabled' => true]]);
    $now = microtime(true);

    event(new JobProcessing('redis', waitingJob($this->app, [RuntimeCollectorService::QUEUED_AT_PAYLOAD_KEY => $now - 2.5, 'delay' => 1])));
    event(new JobProcessing('redis', waitingJob($this->app, ['pushedAt' => (string) ($now - 0.5)])));
    event(new JobProcessing('redis', waitingJob($this->app, [RuntimeCollectorService::QUEUED_AT_PAYLOAD_KEY => $now - 60], attempts: 2)));
    event(new JobProcessing('redis', waitingJob($this->app, [])));
    $this->app->terminate();

    $wait = bufferedOfType($this->app, 'queueWait');

    expect($wait)->toHaveCount(1)
        ->and($wait[0])->toMatchArray(['job' => RuntimeMonitoringTestJob::class, 'count' => 2, 'errorCount' => 0])
        ->and($wait[0])->toHaveKey('queue')
        ->and($wait[0]['maxMs'])->toBeGreaterThanOrEqual(1500.0)
        ->and($wait[0]['maxMs'])->toBeLessThan(5000.0)
        ->and($wait[0]['totalMs'])->toBeGreaterThanOrEqual(2000.0);
});

it('stamps pushed jobs so the worker can measure their wait', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'queueWait' => ['enabled' => true]]);
    config(['queue.default' => 'sync']);

    RuntimeMonitoringTestJob::dispatch();

    $wait = bufferedOfType($this->app, 'queueWait');

    expect($wait)->toHaveCount(1)
        ->and($wait[0])->toMatchArray(['job' => RuntimeMonitoringTestJob::class, 'count' => 1])
        ->and($wait[0]['maxMs'])->toBeLessThan(1000.0);
});

it('counts cache hits, misses, writes and deletes per store and masked key prefix, never the key or value', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'cache' => ['enabled' => true]]);

    Cache::put('users:48213:profile', 'cache-value-secret');
    Cache::get('users:48213:profile');
    Cache::get('reports.daily');
    Cache::forget('users:48213:profile');
    Cache::get('customer-email-secret@example.test');
    Cache::get('a1b2c3token');
    $this->app->terminate();

    $cache = bufferedOfType($this->app, 'cache')->keyBy('keyPrefix');
    $raw = rawBuffer($this->app);

    expect($cache->keys()->all())->toEqualCanonicalizing(['users', 'reports', RuntimeCollectorService::MASKED_KEY_PREFIX])
        ->and($cache['users'])->toMatchArray(['store' => config('cache.default'), 'count' => 3, 'errorCount' => 0, 'hits' => 1, 'misses' => 0, 'writes' => 1, 'deletes' => 1])
        ->and($cache['reports'])->toMatchArray(['count' => 1, 'hits' => 0, 'misses' => 1])
        ->and($cache[RuntimeCollectorService::MASKED_KEY_PREFIX])->toMatchArray(['count' => 2, 'misses' => 2]);

    foreach (['48213', 'profile', 'cache-value-secret', 'daily', 'customer-email-secret', 'a1b2c3token'] as $secret) {
        expect($raw)->not->toContain($secret);
    }
});

it('masks cache key prefixes that look like ids, tokens or personal data', function (string $key, string $expected) {
    bootRuntimeProject($this->app, ['enabled' => true, 'cache' => ['enabled' => true]]);

    expect($this->app->make(RuntimeCollectorService::class)->keyPrefix($key))->toBe($expected);
})->with([
    'plain prefix' => ['illuminate:queue:restart', 'illuminate'],
    'dotted prefix' => ['settings.site', 'settings'],
    'whole key without separator' => ['laravel_sessions', 'laravel_sessions'],
    'masked token kept as a placeholder' => ['framework/schedule-'.sha1('task'), 'framework/schedule-[token]'],
    'number' => ['48213', '[masked]'],
    'id with letters' => ['user42:profile', '[masked]'],
    'uuid' => ['9b2f6c1e-3d4a-4b5c-8d6e-7f8091a2b3c4', '[masked]'],
    'hex only' => ['deadbeefcafe:x', '[masked]'],
    'email' => ['someone@example.test', '[masked]'],
    'spaces' => ['john smith', '[masked]'],
    'empty' => [':orphan', '[masked]'],
    'too long' => [str_repeat('z', 65), '[masked]'],
    'only a placeholder' => ['+41791234567:otp', '[masked]'],
]);

it('counts sent mail per mailable and mailer without recipients, subjects or bodies', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'mail' => ['enabled' => true]]);
    config(['mail.default' => 'array']);

    Mail::to('mail-recipient-secret@example.test')->send(new RuntimeMonitoringTestMail);
    Mail::to('mail-recipient-secret@example.test')->send(new RuntimeMonitoringTestMail);
    Mail::raw('raw-body-secret', fn ($message) => $message->to('raw-recipient-secret@example.test'));
    $this->app->terminate();

    $mail = bufferedOfType($this->app, 'mail')->keyBy('mailable');
    $raw = rawBuffer($this->app);

    expect($mail[RuntimeMonitoringTestMail::class])->toMatchArray(['mailer' => 'array', 'count' => 2, 'errorCount' => 0])
        ->and($mail['unknown'])->toMatchArray(['mailer' => 'array', 'count' => 1]);

    foreach (['recipient-secret', 'mail-subject-secret', 'mail-body-secret', 'raw-body-secret'] as $secret) {
        expect($raw)->not->toContain($secret);
    }
});

it('counts a failed mail when exception capture sees a mail transport exception', function () {
    bootExcubiaDocument($this->app, [
        'runtime' => ['enabled' => true, 'mail' => ['enabled' => true]],
        'exceptions' => ['enabled' => true, 'ignore' => [TransportException::class]],
    ]);

    event(new MessageSending(new Email, ['__laravel_mailable' => RuntimeMonitoringTestMail::class, 'mailer' => 'smtp', 'to' => 'failed-recipient-secret@example.test']));
    report(new TransportException('smtp-transport-secret'));
    report(new RuntimeException('wrapped', 0, new TransportException('wrapped-transport-secret')));
    report(new RuntimeException('not-a-mail-failure'));
    $this->app->terminate();

    $mail = bufferedOfType($this->app, 'mail')->keyBy('mailable');

    expect($mail->keys()->all())->toEqualCanonicalizing([RuntimeMonitoringTestMail::class, 'unknown'])
        ->and($mail[RuntimeMonitoringTestMail::class])->toMatchArray(['mailer' => 'smtp', 'count' => 1, 'errorCount' => 1])
        ->and($mail['unknown'])->toMatchArray(['mailer' => 'unknown', 'count' => 1, 'errorCount' => 1])
        ->and(rawBuffer($this->app))->not->toContain('secret');
});

it('counts no mail failure when the mail type is off', function () {
    bootExcubiaDocument($this->app, ['runtime' => ['enabled' => true], 'exceptions' => ['enabled' => true]]);

    report(new TransportException('smtp-transport-secret'));
    $this->app->terminate();

    expect(bufferedOfType($this->app, 'mail'))->toBeEmpty();
});

it('counts sent and failed notifications per class and channel without the notifiable', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'notifications' => ['enabled' => true]]);
    $notifiable = (new AnonymousNotifiable)->route(RuntimeMonitoringTestChannel::class, 'notifiable-route-secret');

    $notifiable->notify(new RuntimeMonitoringTestNotification);

    try {
        $notifiable->notify(new RuntimeMonitoringTestNotification(true));
    } catch (RuntimeException) {
        // The channel's exception reaches the caller after NotificationFailed.
    }

    $this->app->terminate();
    $notifications = bufferedOfType($this->app, 'notification');
    $raw = rawBuffer($this->app);

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0])->toMatchArray(['notification' => RuntimeMonitoringTestNotification::class, 'channel' => RuntimeMonitoringTestChannel::class, 'count' => 2, 'errorCount' => 1])
        ->and($raw)->not->toContain('notifiable-route-secret')
        ->and($raw)->not->toContain('notification-exception-secret');
});

it('counts log entries per level, never the message or the context', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'logLevels' => ['enabled' => true]]);

    Log::warning('log-message-secret', ['user' => 'log-context-secret']);
    Log::warning('another-message-secret');
    Log::channel('single')->error('channel-message-secret');
    event(new MessageLogged('custom-level-secret', 'message'));
    $this->app->terminate();

    $levels = bufferedOfType($this->app, 'logLevel')->keyBy('level');

    expect($levels->keys()->all())->toEqualCanonicalizing(['warning', 'error', 'unknown'])
        ->and($levels['warning'])->toMatchArray(['count' => 2, 'errorCount' => 0])
        ->and($levels['error'])->toMatchArray(['count' => 1])
        ->and(rawBuffer($this->app))->not->toContain('secret');
});

it('ignores messages logged while the log listener runs, so it cannot loop', function () {
    $project = bootRuntimeProject($this->app, null);
    $clockCalls = 0;
    $collector = new RuntimeCollectorService(
        new RuntimeConfigurationData(isEnabled: true, enabledTypes: ['logLevel' => true]),
        new RuntimeBufferService($this->app->storagePath('excubia/runtime'), RuntimeConfigurationData::DEFAULT_BUFFER_MAX_BYTES),
        $project,
        function () use (&$clockCalls): float {
            $clockCalls++;
            Log::info('logged-inside-the-listener');

            return microtime(true);
        },
    );
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $collector->recordLog($event));

    Log::error('outer');
    $collector->flush();

    $levels = bufferedOfType($this->app, 'logLevel');

    expect($levels)->toHaveCount(1)
        ->and($levels[0])->toMatchArray(['level' => 'error', 'count' => 1])
        ->and($clockCalls)->toBeLessThan(5);
});

it('records nothing for a 1.0.0-beta.2 type with sample rate 0 and keeps the rate on each aggregate', function () {
    $overrides = ['cache' => ['sampleRate' => 0.5]];

    foreach (['requestMemory', 'jobMemory', 'queueWait', 'mail', 'notifications', 'logLevels'] as $key) {
        $overrides[$key] = ['sampleRate' => 0];
    }

    bootRuntimeProject($this->app, allRuntimeTypes($overrides));
    config(['queue.default' => 'sync', 'mail.default' => 'array']);
    Route::get('/sampled', fn () => 'ok');
    mt_srand(132);

    $this->get('/sampled')->assertOk();
    RuntimeMonitoringTestJob::dispatch();
    Mail::to('x@example.test')->send(new RuntimeMonitoringTestMail);
    (new AnonymousNotifiable)->route(RuntimeMonitoringTestChannel::class, 'x')->notify(new RuntimeMonitoringTestNotification);
    Log::warning('sampled');

    for ($i = 0; $i < 400; $i++) {
        Cache::get('sampled:key');
    }

    $this->app->terminate();
    $types = collect(RuntimeBufferService::merge(bufferedRuntime($this->app)))->pluck('type')->unique()->values()->all();
    $cache = bufferedOfType($this->app, 'cache')[0];

    expect(array_values(array_intersect($types, ['requestMemory', 'jobMemory', 'queueWait', 'mail', 'notification', 'logLevel'])))->toBe([])
        ->and($cache['sampleRate'])->toBe(0.5)
        ->and($cache['count'])->toBeGreaterThan(140)
        ->and($cache['count'])->toBeLessThan(260);
});

it('never lets an exception inside the new listeners reach the host app', function () {
    bootRuntimeProject($this->app, allRuntimeTypes());
    $blocked = temporaryProject(['storage' => 'not a directory']).'/storage';
    $this->app->useStoragePath($blocked);
    $this->app->register(ExcubiaServiceProvider::class, true);
    config(['queue.default' => 'sync', 'mail.default' => 'array']);
    $collector = $this->app->make(RuntimeCollectorService::class);

    Cache::put('still:works', 'yes');
    Log::warning('still works');
    RuntimeMonitoringTestJob::dispatch();
    Mail::to('x@example.test')->send(new RuntimeMonitoringTestMail);
    $collector->recordNotification(new NotificationSent(new stdClass, new stdClass, ['not a string']));
    $collector->recordMailFailure(new TransportException('x'));
    $this->app->terminate();

    expect(Cache::get('still:works'))->toBe('yes')
        ->and(is_file($blocked))->toBeTrue();
});
