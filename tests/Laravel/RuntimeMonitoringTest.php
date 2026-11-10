<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\Logger;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Ai;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Audio;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Events\Classifying;
use Laravel\Ai\Events\GeneratingImage;
use Laravel\Ai\Events\ImageFailed;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Prompts\ImagePrompt;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RerankingUsage;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\RerankingResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Tools\ToolNameResolver;
use Laravel\Ai\Transcription;
use Monolog\Handler\TestHandler;
use Qiick\Excubia\Contracts\RuntimeSamplerContract;
use Qiick\Excubia\Data\RuntimeConfigurationData;
use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\LogChannelTapService;
use Qiick\Excubia\Laravel\RuntimeCollectorService;
use Qiick\Excubia\Services\RuntimeBufferService;
use Qiick\Excubia\Tests\Support\FakeAiAgent;
use Qiick\Excubia\Tests\Support\FakeAiTool;
use Qiick\Excubia\Tests\Support\FixedShareSampler;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
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

/** A host application's own logging tap, which must keep running. */
final class RuntimeMonitoringTestLogTap
{
    public static int $calls = 0;

    public function __invoke(Logger $logger): void
    {
        self::$calls++;
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

const TIMED_RUNTIME_TYPES = ['request', 'slowQuery', 'job', 'scheduledTask', 'outgoingHttp', 'queueWait'];

/**
 * A histogram with these counts per bucket index and zero elsewhere.
 *
 * @param  array<int, int>  $counts
 * @return list<int>
 */
function runtimeHistogram(array $counts): array
{
    return array_replace(array_fill(0, count(RuntimeTypeEnum::HISTOGRAM_BOUNDS_MS) + 1, 0), $counts);
}

it('puts each duration in the first bucket whose upper bound holds it, else in the overflow bucket', function (float $durationMs, int $bucket) {
    expect(RuntimeTypeEnum::histogramBucket($durationMs))->toBe($bucket);
})->with([
    '0 ms' => [0.0, 0],
    'on the first bound' => [5.0, 0],
    'just above the first bound' => [5.001, 1],
    'inside 25-50' => [30.0, 3],
    'on the last bound' => [10000.0, 10],
    'above the last bound' => [10000.001, 11],
]);

it('emits a latency histogram that adds up to count on every timed type, also merged across flushes', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'slowQueries' => ['thresholdMs' => 0], 'queueWait' => ['enabled' => true], 'requestMemory' => ['enabled' => true]]);
    config(['queue.default' => 'sync']);
    Route::get('/timed', fn () => 'ok')->name('timed');
    Http::fake(['api.example.test/*' => Http::response(['ok' => true])]);
    $task = $this->app->make(Schedule::class)->command('inspire');
    $connection = DB::connection();

    for ($round = 0; $round < 2; $round++) {
        $this->get('/timed')->assertOk();
        event(new QueryExecuted('select 1', [], 3.0, $connection));
        event(new QueryExecuted('select 1', [], 30.0, $connection));
        RuntimeMonitoringTestJob::dispatch();
        event(new ScheduledTaskFinished($task, 0.25));
        event(new ScheduledTaskFinished($task, 12.0));
        Http::get('https://api.example.test/ping');
        $this->app->terminate();
    }

    $lines = collect(bufferedRuntime($this->app));
    $merged = collect(RuntimeBufferService::merge($lines->all()))->keyBy('type');

    expect($merged->keys()->intersect(TIMED_RUNTIME_TYPES)->values()->all())->toEqualCanonicalizing(TIMED_RUNTIME_TYPES)
        ->and($lines->whereIn('type', TIMED_RUNTIME_TYPES)->count())->toBeGreaterThan(count(TIMED_RUNTIME_TYPES));

    foreach ($lines->whereIn('type', TIMED_RUNTIME_TYPES) as $line) {
        expect($line['histogram'])->toHaveCount(count(RuntimeTypeEnum::HISTOGRAM_BOUNDS_MS) + 1)
            ->and(array_sum($line['histogram']))->toBe($line['count']);
    }

    foreach (TIMED_RUNTIME_TYPES as $type) {
        expect(array_sum($merged[$type]['histogram']))->toBe($merged[$type]['count']);
    }

    expect($merged['slowQuery']['count'])->toBe(4)
        ->and($merged['slowQuery']['histogram'])->toBe(runtimeHistogram([0 => 2, 3 => 2]))
        ->and($merged['scheduledTask']['count'])->toBe(4)
        ->and($merged['scheduledTask']['histogram'])->toBe(runtimeHistogram([5 => 2, 11 => 2]))
        ->and($merged['job']['count'])->toBe(2)
        ->and($merged['queueWait']['count'])->toBe(2)
        ->and($merged['request']['count'])->toBe(2)
        ->and($merged['outgoingHttp']['count'])->toBe(2)
        ->and($merged['requestMemory'])->not->toHaveKey('histogram');
});

it('records nothing for a type with sample rate 0', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'requests' => ['sampleRate' => 0]]);
    Route::get('/sampled', fn () => 'ok');

    $this->get('/sampled')->assertOk();

    expect(bufferedRuntime($this->app))->toBe([]);
});

it('records the configured share of events and keeps the rate on each aggregate', function () {
    $this->app->instance(RuntimeSamplerContract::class, new FixedShareSampler);
    bootRuntimeProject($this->app, ['enabled' => true, 'scheduler' => ['sampleRate' => 0.5]]);
    $task = $this->app->make(Schedule::class)->command('inspire');

    for ($i = 0; $i < 400; $i++) {
        event(new ScheduledTaskSkipped($task));
    }

    $aggregate = RuntimeBufferService::merge(bufferedRuntime($this->app))[0];

    expect($aggregate['sampleRate'])->toBe(0.5)
        ->and($aggregate['count'])->toBe(200);
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
    $now = ($this->app->make(RuntimeCollectorService::CLOCK_BINDING))();

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
    // The push stamp is real time, so the collector reads the real clock too.
    $this->app->forgetInstance(RuntimeCollectorService::CLOCK_BINDING);
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

/**
 * Register the `failing` mailer, whose transport always throws a Symfony
 * Mailer transport exception naming a host, and make `array` the default.
 */
function registerFailingMailer(): void
{
    config([
        'mail.default' => 'array',
        'mail.mailers.failing' => ['transport' => 'failing'],
    ]);
    Mail::extend('failing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Connection to smtp.mail-host-secret.test:587 refused for failed-recipient-secret@example.test');
        }

        public function __toString(): string
        {
            return 'failing://';
        }
    });
}

it('counts a failed mail once when exception capture also reports the transport exception', function () {
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

    expect($mail->keys()->all())->toBe([RuntimeMonitoringTestMail::class])
        ->and($mail[RuntimeMonitoringTestMail::class])->toMatchArray(['mailer' => 'smtp', 'count' => 1, 'errorCount' => 1])
        ->and(rawBuffer($this->app))->not->toContain('secret');
});

it('counts failed mail sends with exception capture off, per mailable and mailer, without hosts or recipients', function () {
    bootExcubiaDocument($this->app, ['runtime' => ['enabled' => true, 'mail' => ['enabled' => true]]]);
    registerFailingMailer();

    try {
        Mail::mailer('failing')->to('failed-recipient-secret@example.test')->send(new RuntimeMonitoringTestMail);
    } catch (TransportException) {
        // The app catches the failure and goes on: the next send settles it.
    }

    Mail::to('mail-recipient-secret@example.test')->send(new RuntimeMonitoringTestMail);

    try {
        Mail::mailer('failing')->to('failed-recipient-secret@example.test')->send(new RuntimeMonitoringTestMail);
    } catch (TransportException) {
        // Still pending when the request terminates.
    }

    $this->app->terminate();

    $mail = bufferedOfType($this->app, 'mail')->keyBy('mailer');
    $raw = rawBuffer($this->app);

    expect($mail['failing'])->toMatchArray(['mailable' => RuntimeMonitoringTestMail::class, 'count' => 2, 'errorCount' => 2])
        ->and($mail['array'])->toMatchArray(['mailable' => RuntimeMonitoringTestMail::class, 'count' => 1, 'errorCount' => 0]);

    foreach (['mail-host-secret', 'smtp.', 'recipient-secret', 'mail-subject-secret', 'mail-body-secret', ':587'] as $secret) {
        expect($raw)->not->toContain($secret);
    }
});

it('counts a queued mail that fails in its job once, also with exception capture on', function (bool $isCapturingExceptions) {
    bootExcubiaDocument($this->app, [
        'runtime' => ['enabled' => true, 'mail' => ['enabled' => true], 'jobs' => ['enabled' => true]],
        'exceptions' => ['enabled' => $isCapturingExceptions],
    ]);
    registerFailingMailer();
    config(['queue.default' => 'sync']);

    try {
        Mail::mailer('failing')->to('failed-recipient-secret@example.test')->queue(new RuntimeMonitoringTestMail);
    } catch (TransportException $exception) {
        report($exception);
    }

    $this->app->terminate();

    $mail = bufferedOfType($this->app, 'mail');

    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toMatchArray(['mailable' => RuntimeMonitoringTestMail::class, 'mailer' => 'failing', 'count' => 1, 'errorCount' => 1])
        ->and(rawBuffer($this->app))->not->toContain('secret');
})->with(['exception capture off' => false, 'exception capture on' => true]);

it('counts a send left pending when its job attempt or Artisan command ends, before that scope flushes', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'mail' => ['enabled' => true]]);
    $sending = fn () => event(new MessageSending(new Email, ['__laravel_mailable' => RuntimeMonitoringTestMail::class, 'mailer' => 'smtp']));

    $sending();
    event(new JobExceptionOccurred('sync', new SyncJob($this->app, '{}', 'sync', 'default'), new RuntimeException('released')));
    $sending();
    event(new CommandFinished('app:send', new ArrayInput([]), new NullOutput, 1));
    $collector = $this->app->make(RuntimeCollectorService::class);
    $collector->flush();

    expect(bufferedOfType($this->app, 'mail')[0])->toMatchArray(['mailer' => 'smtp', 'count' => 2, 'errorCount' => 2]);
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

it('adds the configured log channel, the stack name for a stack, and omits an unknown one', function () {
    config([
        'logging.default' => 'stack',
        'logging.channels.stack' => ['driver' => 'stack', 'channels' => ['single', 'recorded']],
        'logging.channels.recorded' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'tap' => [RuntimeMonitoringTestLogTap::class]],
    ]);
    RuntimeMonitoringTestLogTap::$calls = 0;
    bootRuntimeProject($this->app, ['enabled' => true, 'logLevels' => ['enabled' => true]]);

    Log::warning('stack-message-secret');
    Log::channel('single')->warning('single-message-secret');
    Log::channel('recorded')->warning('recorded-message-secret', ['user' => 'recorded-context-secret']);
    Log::build(['driver' => 'single', 'path' => storage_path('logs/on-demand.log')])->warning('on-demand-secret');
    event(new MessageLogged('warning', 'by-hand-secret'));
    $this->app->terminate();

    $warnings = bufferedOfType($this->app, 'logLevel')->keyBy(fn (array $aggregate): string => $aggregate['channel'] ?? 'none');
    $record = collect(Log::channel('recorded')->getLogger()->getHandlers()[0]->getRecords())->last();

    expect($warnings->keys()->all())->toEqualCanonicalizing(['stack', 'single', 'recorded', 'none'])
        ->and($warnings['stack']['count'])->toBe(1)
        ->and($warnings['none']['count'])->toBe(2)
        ->and($warnings['none'])->not->toHaveKey('channel')
        ->and(RuntimeMonitoringTestLogTap::$calls)->toBe(1)
        ->and($record->message)->toBe('recorded-message-secret')
        ->and($record->context)->toBe(['user' => 'recorded-context-secret'])
        ->and($record->extra)->toBe([])
        ->and(rawBuffer($this->app))->not->toContain('secret');
});

it('watches the log channels resolved before Excubia booted, once', function () {
    Log::channel('single');
    bootRuntimeProject($this->app, ['enabled' => true, 'logLevels' => ['enabled' => true]]);
    $this->app->make(RuntimeCollectorService::class)->tapLogChannels($this->app);

    Log::channel('single')->error('early-channel-secret');
    $this->app->terminate();

    $taps = config('logging.channels.single.tap');

    expect(bufferedOfType($this->app, 'logLevel')->all())->toHaveCount(1)
        ->and(bufferedOfType($this->app, 'logLevel')[0])->toMatchArray(['level' => 'error', 'channel' => 'single', 'count' => 1])
        ->and(array_count_values($taps)[LogChannelTapService::class.':single'])->toBe(1);
});

it('leaves the log channels alone when log levels are off, and a stray tap never throws', function () {
    bootRuntimeProject($this->app, ['enabled' => true]);

    (new LogChannelTapService($this->app))(Log::channel('single'), 'single');
    Log::channel('single')->error('still logged');

    expect(config('logging.channels.single.tap'))->toBeNull()
        ->and(Log::channel('single')->getLogger()->getHandlers())->not->toBeEmpty();
});

it('records nothing for a 1.0.0-beta.2 type with sample rate 0 and keeps the rate on each aggregate', function () {
    $overrides = ['cache' => ['sampleRate' => 0.5]];

    foreach (['requestMemory', 'jobMemory', 'queueWait', 'mail', 'notifications', 'logLevels'] as $key) {
        $overrides[$key] = ['sampleRate' => 0];
    }

    $this->app->instance(RuntimeSamplerContract::class, new FixedShareSampler);
    bootRuntimeProject($this->app, allRuntimeTypes($overrides));
    config(['queue.default' => 'sync', 'mail.default' => 'array']);
    Route::get('/sampled', fn () => 'ok');

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
        ->and($cache['count'])->toBe(200);
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
    $collector->settlePendingMail();
    $this->app->terminate();

    expect(Cache::get('still:works'))->toBe('yes')
        ->and(is_file($blocked))->toBeTrue();
});

/**
 * The PHP error handler on top of the stack right now (get_error_handler()
 * is PHP 8.5 only).
 */
function currentErrorHandler(): ?callable
{
    $handler = set_error_handler(static fn (): bool => false);
    restore_error_handler();

    return $handler;
}

/**
 * A project with deprecations on and files that raise deprecations when
 * included: one in the app, one in a vendor package, one that PHP itself
 * deprecates (null to a string parameter, no strict types). The app message
 * carries an absolute path, a secret and an email, which must not reach the
 * buffer.
 *
 * @return array<string, string>
 */
function deprecationFiles(): array
{
    return [
        'excubia.json' => json_encode(['runtime' => ['enabled' => true, 'deprecations' => ['enabled' => true]]], JSON_THROW_ON_ERROR),
        'app/Legacy.php' => "<?php\ntrigger_error('App\\\\Legacy::old() is deprecated, token=deprecation-secret, see /srv/www/private/notes.txt or ops@example.test', E_USER_DEPRECATED);\n",
        'vendor/acme/widgets/src/Old.php' => "<?php\ntrigger_error('Acme\\\\Widget::render() is deprecated since 2.0, '.str_repeat('long ', 100), E_USER_DEPRECATED);\n",
        'app/Native.php' => "<?php\nstrlen(null);\n",
    ];
}

it('counts app, vendor and native deprecations by source, masked message and PHP version', function () {
    $project = temporaryProject(deprecationFiles());
    $this->app->setBasePath($project);
    $this->app->useStoragePath(temporaryProject([]));
    $this->app->register(ExcubiaServiceProvider::class, true);

    for ($round = 0; $round < 2; $round++) {
        include $project.'/app/Legacy.php';
        include $project.'/vendor/acme/widgets/src/Old.php';
        include $project.'/app/Native.php';
    }

    $this->app->terminate();
    $deprecations = bufferedOfType($this->app, 'deprecation');
    $phpVersion = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    $app = $deprecations->firstWhere('message', 'App\Legacy::old() is deprecated, token=[masked], see notes.txt or [email]');
    $vendor = $deprecations->firstWhere('source', 'vendor/acme/widgets');
    $native = $deprecations->first(fn (array $aggregate): bool => str_contains($aggregate['message'], 'strlen()'));
    $raw = rawBuffer($this->app);

    expect($deprecations)->toHaveCount(3)
        ->and($app)->toMatchArray(['source' => 'app', 'phpVersion' => $phpVersion, 'count' => 2, 'errorCount' => 0, 'sampleRate' => 1])
        ->and(array_keys($app))->toBe(['type', 'bucket', 'sampleRate', 'source', 'message', 'phpVersion', 'count', 'errorCount'])
        ->and($vendor)->toMatchArray(['phpVersion' => $phpVersion, 'count' => 2])
        ->and(mb_strlen($vendor['message']))->toBe(RuntimeCollectorService::MAX_DEPRECATION_MESSAGE_LENGTH)
        ->and($vendor['message'])->toStartWith('Acme\Widget::render() is deprecated since 2.0, long')
        ->and($native)->toMatchArray(['source' => 'app', 'count' => 2])
        ->and($raw)->not->toContain('secret')
        ->and($raw)->not->toContain($project)
        ->and($raw)->not->toContain('/srv/www')
        ->and($raw)->not->toContain('example.test')
        ->and($raw)->not->toContain('Legacy.php')
        ->and($raw)->not->toContain('Old.php');
});

it('names the source of a deprecation without its path', function (string $file, string $source) {
    $collector = new RuntimeCollectorService(new RuntimeConfigurationData, new RuntimeBufferService(temporaryProject([]), RuntimeConfigurationData::DEFAULT_BUFFER_MAX_BYTES), '/srv/project');

    expect($collector->deprecationSource($file))->toBe($source);
})->with([
    'app file' => ['/srv/project/app/Models/User.php', 'app'],
    'compiled view' => ['/srv/project/storage/framework/views/abc.php', 'app'],
    'eval in the app' => ["/srv/project/app/Legacy.php(12) : eval()'d code", 'app'],
    'vendor package' => ['/srv/project/vendor/laravel/framework/src/Illuminate/Support/Str.php', 'vendor/laravel/framework'],
    'composer file' => ['/srv/project/vendor/composer/ClassLoader.php', 'vendor/composer'],
    'outside the project' => ['/usr/share/php/Legacy.php', 'unknown'],
    'sibling with the same prefix' => ['/srv/project-old/app/User.php', 'unknown'],
    'no file' => ['', 'unknown'],
]);

it('reads Windows paths as well', function () {
    $collector = new RuntimeCollectorService(new RuntimeConfigurationData, new RuntimeBufferService(temporaryProject([]), RuntimeConfigurationData::DEFAULT_BUFFER_MAX_BYTES), 'C:\\project');

    expect($collector->deprecationSource('C:\\project\\vendor\\acme\\widgets\\src\\Old.php'))->toBe('vendor/acme/widgets')
        ->and($collector->deprecationSource('C:\\project\\app\\Legacy.php'))->toBe('app');
});

it('always runs the previous error handler and returns its result', function () {
    $calls = [];
    set_error_handler(function (int $level, string $message) use (&$calls): bool {
        $calls[] = [$level, $message];

        return true;
    });

    try {
        bootRuntimeProject($this->app, ['enabled' => true, 'deprecations' => ['enabled' => true]]);
        $handler = currentErrorHandler();

        trigger_error('old api', E_USER_DEPRECATED);
        trigger_error('a warning', E_USER_WARNING);
        trigger_error('a notice', E_USER_NOTICE);
        @trigger_error('silenced', E_USER_DEPRECATED);
        $result = $handler(E_DEPRECATED, 'direct call', __FILE__, __LINE__);
    } finally {
        restore_error_handler();
        restore_error_handler();
    }

    $this->app->terminate();

    expect($calls)->toBe([[E_USER_DEPRECATED, 'old api'], [E_USER_WARNING, 'a warning'], [E_USER_NOTICE, 'a notice'], [E_USER_DEPRECATED, 'silenced'], [E_DEPRECATED, 'direct call']])
        ->and($result)->toBeTrue()
        ->and(bufferedOfType($this->app, 'deprecation')->sum('count'))->toBe(3);
});

it('returns false without a previous handler so PHP handles the error as before', function () {
    $collector = new RuntimeCollectorService(
        new RuntimeConfigurationData(isEnabled: true, enabledTypes: ['deprecation' => true]),
        new RuntimeBufferService(temporaryProject([]), RuntimeConfigurationData::DEFAULT_BUFFER_MAX_BYTES),
        '/srv/project',
    );
    set_error_handler(null);

    try {
        $collector->listenToDeprecations();
        $handler = currentErrorHandler();
    } finally {
        restore_error_handler();
        restore_error_handler();
    }

    expect($handler(E_USER_DEPRECATED, 'old api', '/srv/project/app/Legacy.php', 3))->toBeFalse()
        ->and($handler(E_USER_WARNING, 'a warning', '/srv/project/app/Legacy.php', 3))->toBeFalse();
});

it('keeps Laravel writing deprecations to its deprecations log and warnings as exceptions', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'deprecations' => ['enabled' => true]]);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = [$event->level, $event->message];
    });

    trigger_error('laravel still logs this', E_USER_DEPRECATED);

    expect(fn () => trigger_error('still an exception', E_USER_WARNING))->toThrow(ErrorException::class, 'still an exception');

    $this->app->terminate();

    expect($logged)->toHaveCount(1)
        ->and($logged[0][0])->toBe('warning')
        ->and($logged[0][1])->toContain('laravel still logs this')
        ->and(bufferedOfType($this->app, 'deprecation')->sum('count'))->toBe(1);
});

it('registers no error handler when deprecations are off', function (?array $runtime) {
    $before = currentErrorHandler();
    bootRuntimeProject($this->app, $runtime);

    expect(currentErrorHandler())->toBe($before);

    trigger_error('not counted', E_USER_DEPRECATED);
    $this->app->terminate();

    expect(bufferedOfType($this->app, 'deprecation'))->toHaveCount(0);
})->with([
    'no excubia.json' => [null],
    'runtime off' => [['enabled' => false, 'deprecations' => ['enabled' => true]]],
    'runtime on, deprecations by default' => [['enabled' => true]],
    'deprecations off' => [['enabled' => true, 'deprecations' => ['enabled' => false]]],
]);

it('registers the error handler when deprecations are on', function () {
    $before = currentErrorHandler();
    bootRuntimeProject($this->app, ['enabled' => true, 'deprecations' => ['enabled' => true]]);

    expect(currentErrorHandler())->not->toBe($before);
});

it('never lets a failure inside the deprecation collector surface, and cannot loop', function () {
    $previousCalls = 0;
    $clockCalls = 0;
    $collector = null;
    $collector = new RuntimeCollectorService(
        new RuntimeConfigurationData(isEnabled: true, enabledTypes: ['deprecation' => true]),
        new RuntimeBufferService(temporaryProject([]), RuntimeConfigurationData::DEFAULT_BUFFER_MAX_BYTES),
        '/srv/project',
        function () use (&$clockCalls, &$collector): float {
            $clockCalls++;
            // PHP never calls an error handler from inside itself, but code the collector runs could reach it another way.
            $collector->recordDeprecation('raised inside the collector', '/srv/project/app/Inner.php');

            throw new RuntimeException('collector-failure');
        },
    );
    set_error_handler(function () use (&$previousCalls): bool {
        $previousCalls++;

        return true;
    });

    try {
        $collector->listenToDeprecations();
        trigger_error('outer', E_USER_DEPRECATED);
    } finally {
        restore_error_handler();
        restore_error_handler();
    }

    $collector->flush();

    expect($previousCalls)->toBe(1)
        ->and($clockCalls)->toBe(1);

    // After the failure the collector still works.
    $collector->recordDeprecation('later', '/srv/project/app/Later.php');

    expect($clockCalls)->toBe(2);
});

it('records no deprecation at sample rate 0', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'deprecations' => ['enabled' => true, 'sampleRate' => 0]]);

    trigger_error('sampled out', E_USER_DEPRECATED);
    $this->app->terminate();

    expect(bufferedOfType($this->app, 'deprecation'))->toHaveCount(0);
});

final class RuntimeDuplicateQueryTestJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public int $queries) {}

    public function handle(): void
    {
        for ($i = 0; $i < $this->queries; $i++) {
            DB::select('select ? as job_column', ['job-binding-secret-'.$i]);
        }
    }
}

/**
 * Run this query shape `$times` times, each with another binding.
 */
function repeatQuery(int $times, string $sql = 'select ? as repeated_column'): void
{
    for ($i = 0; $i < $times; $i++) {
        DB::select($sql, ['binding-secret-'.$i]);
    }
}

it('detects a query repeated with different bindings once per request, with its repeat count and never a binding', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'duplicateQueries' => ['enabled' => true]]);
    Route::get('/orders', function () {
        repeatQuery(10);
        DB::select("select 'literal-secret' as other_column");

        return 'ok';
    })->name('orders.index');

    $this->get('/orders?email=query-secret@example.test')->assertOk();

    $duplicates = bufferedOfType($this->app, 'duplicateQuery');
    $raw = rawBuffer($this->app);

    expect($duplicates)->toHaveCount(1)
        ->and($duplicates[0])->toBe([
            'type' => 'duplicateQuery',
            'bucket' => $duplicates[0]['bucket'],
            'sampleRate' => 1, // JSON writes 1.0 as 1
            'origin' => 'request',
            'name' => 'orders.index',
            'sql' => 'select ? as repeated_column',
            'connection' => DB::getDefaultConnection(),
            'count' => 1,
            'errorCount' => 0,
            'totalRepeats' => 10,
            'maxRepeats' => 10,
        ])
        ->and($raw)->not->toContain('binding-secret')
        ->and($raw)->not->toContain('literal-secret')
        ->and($raw)->not->toContain('query-secret')
        ->and($raw)->not->toContain($this->app->basePath());
});

it('records nothing for a shape below the threshold, and honours a custom threshold', function (?int $threshold, int $times, int $expected) {
    bootRuntimeProject($this->app, ['enabled' => true, 'duplicateQueries' => array_filter(['enabled' => true, 'threshold' => $threshold])]);
    Route::get('/few', function () use ($times) {
        repeatQuery($times);

        return 'ok';
    })->name('few');

    $this->get('/few')->assertOk();

    expect(bufferedOfType($this->app, 'duplicateQuery'))->toHaveCount($expected);
})->with([
    '3 queries, default threshold 5' => [null, 3, 0],
    '4 queries, default threshold 5' => [null, 4, 0],
    '5 queries, default threshold 5' => [null, 5, 1],
    '3 queries, threshold 3' => [3, 3, 1],
]);

it('counts IN lists of different lengths and different whitespace as one shape', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'duplicateQueries' => ['enabled' => true]]);
    Route::get('/lists', function () {
        for ($length = 1; $length <= 6; $length++) {
            DB::table('sqlite_master')->whereIn('name', array_map(static fn (int $i): string => 'in-secret-'.$i, range(1, $length)))->get();
        }

        DB::select("select   ?\n as   spaced", ['a']);
        DB::select('select ? as spaced', ['b']);

        return 'ok';
    })->name('lists');

    $this->get('/lists')->assertOk();

    $duplicates = bufferedOfType($this->app, 'duplicateQuery');

    expect($duplicates)->toHaveCount(1)
        ->and($duplicates[0]['sql'])->toBe('select * from "sqlite_master" where "name" in (…)')
        ->and($duplicates[0]['maxRepeats'])->toBe(6)
        ->and(rawBuffer($this->app))->not->toContain('in-secret');
});

it('normalises IN lists and whitespace for duplicate queries', function (string $sql, string $expected) {
    expect(RuntimeCollectorService::normaliseSql($sql))->toBe($expected);
})->with([
    'placeholders' => ['select * from users where id in (?, ?, ?) and team_id IN(?)', 'select * from users where id in (…) and team_id IN (…)'],
    'numbered placeholders' => ['select * from users where id not in ($1,$2)', 'select * from users where id not in (…)'],
    'inline literals' => ["select * from users where id in (1, 2, 3) or email in ('a@example.test')", 'select * from users where id in (…) or email in (…)'],
    'whitespace' => ["  select *\n\tfrom   users\r\n where id = ?  ", 'select * from users where id = ?'],
    'subquery stays' => ['select * from users where id in (select user_id from posts)', 'select * from users where id in (select user_id from posts)'],
    'function names ending in in stay' => ['select join(?) from t', 'select join(?) from t'],
]);

it('keeps the slow query SQL unchanged and cuts normalised SQL to 2000 characters', function () {
    expect(RuntimeCollectorService::maskSql("select * from users where id in (1, 2)\n  and a = ?"))->toBe("select * from users where id in (?, ?)\n  and a = ?")
        ->and(mb_strlen(RuntimeCollectorService::normaliseSql(str_repeat('x ', 5000))))->toBe(RuntimeCollectorService::MAX_SQL_LENGTH);
});

it('records a repeated long query cut to 2000 characters', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'duplicateQueries' => ['enabled' => true]]);
    $longSql = 'select ? as id'.str_repeat(', ? as c', 1000);
    Route::get('/long', function () use ($longSql) {
        for ($i = 0; $i < 5; $i++) {
            DB::select($longSql, array_fill(0, 1001, 'long-binding-secret'));
        }

        return 'ok';
    })->name('long');

    $this->get('/long')->assertOk();

    $duplicates = bufferedOfType($this->app, 'duplicateQuery');

    expect($duplicates)->toHaveCount(1)
        ->and(mb_strlen($duplicates[0]['sql']))->toBe(RuntimeCollectorService::MAX_SQL_LENGTH)
        ->and($duplicates[0]['maxRepeats'])->toBe(5)
        ->and(rawBuffer($this->app))->not->toContain('long-binding-secret');
});

it('merges occurrences across requests: count of requests, total and max repeats', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'duplicateQueries' => ['enabled' => true]]);
    Route::get('/loop/{times}', function (string $times) {
        repeatQuery((int) $times);

        return 'ok';
    })->name('loop');

    $this->get('/loop/10')->assertOk();
    $this->get('/loop/6')->assertOk();
    $this->get('/loop/2')->assertOk();

    $duplicates = bufferedOfType($this->app, 'duplicateQuery');

    expect($duplicates)->toHaveCount(1)
        ->and($duplicates[0])->toMatchArray(['count' => 2, 'totalRepeats' => 16, 'maxRepeats' => 10]);
});

it('does not leak counts between requests or between a request and its jobs', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'duplicateQueries' => ['enabled' => true]]);
    config(['queue.default' => 'sync']);
    Route::get('/three', function () {
        repeatQuery(3);

        return 'ok';
    })->name('three');
    Route::get('/with-jobs', function () {
        DB::select('select ? as job_column', ['request-binding']);
        DB::select('select ? as job_column', ['request-binding']);
        RuntimeDuplicateQueryTestJob::dispatch(3);
        RuntimeDuplicateQueryTestJob::dispatch(3);
        DB::select('select ? as job_column', ['request-binding']);

        return 'ok';
    })->name('with-jobs');

    $this->get('/three')->assertOk();
    $this->get('/three')->assertOk();
    $this->get('/with-jobs')->assertOk();

    // Outside a request: counted, then dropped when the process terminates.
    repeatQuery(3);
    $this->app->terminate();
    $this->get('/three')->assertOk();

    expect(bufferedOfType($this->app, 'duplicateQuery'))->toHaveCount(0);
});

it('records a job by its class and keeps the request around a sync job apart', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'duplicateQueries' => ['enabled' => true]]);
    config(['queue.default' => 'sync']);
    Route::get('/dispatch', function () {
        repeatQuery(2);
        RuntimeDuplicateQueryTestJob::dispatch(7);
        repeatQuery(4);

        return 'ok';
    })->name('dispatch');

    $this->get('/dispatch')->assertOk();

    $duplicates = bufferedOfType($this->app, 'duplicateQuery')->keyBy('origin');

    expect($duplicates)->toHaveCount(2)
        ->and($duplicates['job'])->toMatchArray(['name' => RuntimeDuplicateQueryTestJob::class, 'sql' => 'select ? as job_column', 'count' => 1, 'maxRepeats' => 7])
        ->and($duplicates['request'])->toMatchArray(['name' => 'dispatch', 'sql' => 'select ? as repeated_column', 'count' => 1, 'maxRepeats' => 6])
        ->and(rawBuffer($this->app))->not->toContain('job-binding-secret');
});

it('evaluates a job once whether it is processed, released after an exception or failed', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'duplicateQueries' => ['enabled' => true]]);
    $exception = new RuntimeException('job-exception-secret');

    $released = waitingJob($this->app, []);
    event(new JobProcessing('redis', $released));
    repeatQuery(5);
    event(new JobExceptionOccurred('redis', $released, $exception));

    $failed = waitingJob($this->app, []);
    event(new JobProcessing('redis', $failed));
    repeatQuery(5);
    event(new JobExceptionOccurred('redis', $failed, $exception));
    event(new JobFailed('redis', $failed, $exception));

    $processed = waitingJob($this->app, []);
    event(new JobProcessing('redis', $processed));
    repeatQuery(5);
    event(new JobProcessed('redis', $processed));

    // A job end without its start is ignored.
    repeatQuery(5);
    event(new JobProcessed('redis', waitingJob($this->app, [])));
    $this->app->terminate();

    $duplicates = bufferedOfType($this->app, 'duplicateQuery');

    expect($duplicates)->toHaveCount(1)
        ->and($duplicates[0])->toMatchArray(['origin' => 'job', 'name' => RuntimeMonitoringTestJob::class, 'count' => 3, 'totalRepeats' => 15, 'maxRepeats' => 5])
        ->and(rawBuffer($this->app))->not->toContain('job-exception-secret');
});

it('counts at most 200 shapes per request and ignores the rest', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'duplicateQueries' => ['enabled' => true]]);
    Route::get('/many', function () {
        for ($shape = 1; $shape <= RuntimeCollectorService::MAX_QUERY_SHAPES; $shape++) {
            DB::select('select ? as column_'.$shape, [$shape]);
        }

        repeatQuery(10);
        repeatQuery(9, 'select ? as column_1');

        return 'ok';
    })->name('many');

    $this->get('/many')->assertOk();

    $duplicates = bufferedOfType($this->app, 'duplicateQuery');

    expect($duplicates)->toHaveCount(1)
        ->and($duplicates[0])->toMatchArray(['sql' => 'select ? as column_1', 'maxRepeats' => 10]);
});

it('registers nothing and records nothing for duplicate queries while the type is off', function (array $runtime) {
    $before = [
        'query' => count(Event::getListeners(QueryExecuted::class)),
        'exception' => count(Event::getListeners(JobExceptionOccurred::class)),
        'request' => count(Event::getListeners(RequestHandled::class)),
    ];
    bootRuntimeProject($this->app, $runtime);
    Route::get('/off', function () {
        repeatQuery(10);

        return 'ok';
    })->name('off');

    expect(count(Event::getListeners(QueryExecuted::class)))->toBe($before['query'])
        ->and(count(Event::getListeners(JobExceptionOccurred::class)))->toBe($before['exception'])
        ->and(count(Event::getListeners(RequestHandled::class)))->toBe($before['request']);

    $this->get('/off')->assertOk();

    expect(bufferedOfType($this->app, 'duplicateQuery'))->toHaveCount(0);
})->with([
    'not switched on' => [['enabled' => true, 'requests' => ['enabled' => false], 'slowQueries' => ['enabled' => false]]],
    'switched off' => [['enabled' => true, 'requests' => ['enabled' => false], 'slowQueries' => ['enabled' => false], 'duplicateQueries' => ['enabled' => false, 'threshold' => 2]]],
    'runtime off' => [['enabled' => false, 'duplicateQueries' => ['enabled' => true]]],
]);

it('registers the query, request and job listeners for duplicate queries alone and records no slow query', function () {
    $before = [
        'query' => count(Event::getListeners(QueryExecuted::class)),
        'exception' => count(Event::getListeners(JobExceptionOccurred::class)),
        'request' => count(Event::getListeners(RequestHandled::class)),
        'processing' => count(Event::getListeners(JobProcessing::class)),
    ];
    bootRuntimeProject($this->app, [
        'enabled' => true,
        'requests' => ['enabled' => false],
        'slowQueries' => ['enabled' => false, 'thresholdMs' => 0],
        'jobs' => ['enabled' => false],
        'duplicateQueries' => ['enabled' => true],
    ]);
    Route::get('/alone', function () {
        repeatQuery(5);

        return 'ok';
    })->name('alone');

    expect(count(Event::getListeners(QueryExecuted::class)))->toBe($before['query'] + 1)
        ->and(count(Event::getListeners(JobExceptionOccurred::class)))->toBe($before['exception'] + 1)
        ->and(count(Event::getListeners(RequestHandled::class)))->toBe($before['request'] + 1)
        ->and(count(Event::getListeners(JobProcessing::class)))->toBe($before['processing'] + 1);

    $this->get('/alone')->assertOk();

    expect(collect(bufferedRuntime($this->app))->pluck('type')->unique()->values()->all())->toBe(['duplicateQuery']);
});

it('records no duplicate query at sample rate 0', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'duplicateQueries' => ['enabled' => true, 'sampleRate' => 0]]);
    Route::get('/sampled', function () {
        repeatQuery(10);

        return 'ok';
    })->name('sampled');

    $this->get('/sampled')->assertOk();

    expect(bufferedOfType($this->app, 'duplicateQuery'))->toHaveCount(0);
});

/**
 * Run an Artisan command as `php artisan` would, with the Symfony console
 * events re-routed to CommandStarting and CommandFinished (Laravel skips
 * that in unit tests).
 *
 * @param  array<string, mixed>  $parameters
 */
function runArtisanWithEvents(Application $app, string $command, array $parameters = []): int
{
    $kernel = $app->make(ConsoleKernelContract::class);
    assert($kernel instanceof ConsoleKernel);
    $kernel->rerouteSymfonyCommandEvents();
    // The console application built before the re-route has no dispatcher.
    $kernel->setArtisan(null);

    return $kernel->call($command, $parameters);
}

function registerRuntimeTestCommands(): void
{
    Artisan::command('runtime:deploy {token} {--password=}', fn () => 0);
    Artisan::command('runtime:broken', fn () => 1);
    Artisan::command('runtime:odd', fn () => 3);
    Artisan::command('runtime:outer', function () {
        Artisan::call('runtime:deploy', ['token' => 'nested-secret']);

        return 0;
    });
}

/**
 * Dispatch a CommandStarting and CommandFinished pair for this command,
 * with whatever the callback dispatches in between.
 */
function dispatchCommand(string $command, int $exitCode = 0, ?Closure $during = null): void
{
    $input = new ArrayInput([]);
    $output = new NullOutput;
    event(new CommandStarting($command, $input, $output));

    if ($during !== null) {
        $during();
    }

    event(new CommandFinished($command, $input, $output, $exitCode));
}

function forgetSchedulerMarker(): void
{
    unset($_ENV[RuntimeCollectorService::SCHEDULER_ENVIRONMENT_VARIABLE]);
    putenv(RuntimeCollectorService::SCHEDULER_ENVIRONMENT_VARIABLE);
}

it('counts an Artisan command run with its duration and exit code class, and flushes when it finishes', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'commands' => ['enabled' => true]]);
    $now = 1_800_000_000.0;
    $collector = new RuntimeCollectorService(
        new RuntimeConfigurationData(isEnabled: true, enabledTypes: ['command' => true]),
        new RuntimeBufferService($this->app->storagePath('excubia/runtime'), RuntimeConfigurationData::DEFAULT_BUFFER_MAX_BYTES),
        $this->app->basePath(),
        function () use (&$now): float {
            $now += 0.04;

            return $now;
        },
    );
    $input = new ArrayInput([]);
    $output = new NullOutput;

    $collector->startCommand(new CommandStarting('migrate', $input, $output));
    $collector->recordCommand(new CommandFinished('migrate', $input, $output, 0));

    expect(bufferedRuntime($this->app))->toHaveCount(1)
        ->and(bufferedRuntime($this->app)[0])->toMatchArray([
            'type' => 'command',
            'command' => 'migrate',
            'exitCodeClass' => '0',
            'count' => 1,
            'errorCount' => 0,
            'totalMs' => 40.0,
            'maxMs' => 40.0,
            'histogram' => runtimeHistogram([3 => 1]),
            'sampleRate' => 1.0,
        ]);
});

it('records real Artisan runs by name and exit code class, never their arguments or options', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'commands' => ['enabled' => true]]);
    registerRuntimeTestCommands();

    runArtisanWithEvents($this->app, 'runtime:deploy', ['token' => 'argument-secret', '--password' => 'option-secret']);
    runArtisanWithEvents($this->app, 'runtime:deploy', ['token' => 'other-secret']);
    runArtisanWithEvents($this->app, 'runtime:broken');
    runArtisanWithEvents($this->app, 'runtime:odd');

    $commands = bufferedOfType($this->app, 'command')->keyBy('command');

    expect($commands->keys()->sort()->values()->all())->toBe(['runtime:broken', 'runtime:deploy', 'runtime:odd'])
        ->and($commands['runtime:deploy'])->toMatchArray(['exitCodeClass' => '0', 'count' => 2, 'errorCount' => 0])
        ->and($commands['runtime:broken'])->toMatchArray(['exitCodeClass' => '1', 'count' => 1, 'errorCount' => 1])
        ->and($commands['runtime:odd'])->toMatchArray(['exitCodeClass' => 'other', 'count' => 1, 'errorCount' => 1])
        ->and(array_sum($commands['runtime:deploy']['histogram']))->toBe(2)
        ->and(rawBuffer($this->app))->not->toContain('secret')
        ->not->toContain('token')
        ->not->toContain('password');
});

it('counts only the top-level command, not the ones it calls', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'commands' => ['enabled' => true]]);
    registerRuntimeTestCommands();

    runArtisanWithEvents($this->app, 'runtime:outer');

    expect(bufferedOfType($this->app, 'command')->pluck('command')->all())->toBe(['runtime:outer'])
        ->and(rawBuffer($this->app))->not->toContain('nested-secret');
});

it('never counts Excubia\'s own commands and skips the ignore list, by default and as configured', function (?array $ignore, array $expected) {
    bootRuntimeProject($this->app, ['enabled' => true, 'commands' => $ignore === null ? ['enabled' => true] : ['enabled' => true, 'ignore' => $ignore]]);

    foreach (['excubia:check', 'excubia:push-runtime', 'schedule:work', 'queue:work', 'queue:listen', 'horizon', 'horizon:supervisor', 'tinker', 'migrate', 'app:sync-users'] as $command) {
        dispatchCommand($command);
    }

    forgetSchedulerMarker();

    expect(bufferedOfType($this->app, 'command')->pluck('command')->sort()->values()->all())->toBe($expected);
})->with([
    'default list' => [null, ['app:sync-users', 'migrate']],
    'custom list replaces the default' => [['app:*'], ['horizon', 'horizon:supervisor', 'migrate', 'queue:listen', 'queue:work', 'schedule:work', 'tinker']],
    'empty list' => [[], ['app:sync-users', 'horizon', 'horizon:supervisor', 'migrate', 'queue:listen', 'queue:work', 'schedule:work', 'tinker']],
]);

it('skips commands run by the scheduler, in its process and in the task processes it starts', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'commands' => ['enabled' => true, 'ignore' => []]]);
    $marker = RuntimeCollectorService::SCHEDULER_ENVIRONMENT_VARIABLE;
    $markerSeenByTasks = null;

    // schedule:run marks the environment its task processes inherit while it
    // runs; a scheduled closure that calls a command runs in its process.
    dispatchCommand('schedule:run', 0, function () use ($marker, &$markerSeenByTasks): void {
        $markerSeenByTasks = [getenv($marker), $_ENV[$marker] ?? null];
        dispatchCommand('app:called-by-a-closure');
    });

    // A task process (`php artisan app:scheduled`) started with the inherited marker.
    putenv($marker.'=1');
    dispatchCommand('app:scheduled');
    forgetSchedulerMarker();

    // Once schedule:run is over, the same process counts commands again.
    dispatchCommand('migrate');

    expect($markerSeenByTasks)->toBe(['1', '1'])
        ->and(getenv($marker))->toBeFalse()
        ->and(bufferedOfType($this->app, 'command')->pluck('command')->sort()->values()->all())->toBe(['migrate', 'schedule:run']);
});

it('keeps the inherited marker when a task process runs the scheduler itself', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'commands' => ['enabled' => true, 'ignore' => []]]);
    $marker = RuntimeCollectorService::SCHEDULER_ENVIRONMENT_VARIABLE;
    putenv($marker.'=1');

    // schedule:work starts schedule:run as a child process with the marker.
    dispatchCommand('schedule:run');
    $markerAfterwards = getenv($marker);
    forgetSchedulerMarker();

    expect($markerAfterwards)->toBe('1')
        ->and(bufferedOfType($this->app, 'command'))->toHaveCount(0);
});

it('registers nothing and records nothing for commands while the type is off', function (array $runtime) {
    $before = [count(Event::getListeners(CommandStarting::class)), count(Event::getListeners(CommandFinished::class))];
    bootRuntimeProject($this->app, $runtime);
    registerRuntimeTestCommands();

    expect([count(Event::getListeners(CommandStarting::class)), count(Event::getListeners(CommandFinished::class))])->toBe($before);

    runArtisanWithEvents($this->app, 'runtime:deploy', ['token' => 'off']);
    dispatchCommand('migrate');

    expect(bufferedOfType($this->app, 'command'))->toHaveCount(0);
})->with([
    'not switched on' => [['enabled' => true]],
    'switched off' => [['enabled' => true, 'commands' => ['enabled' => false]]],
    'runtime off' => [['enabled' => false, 'commands' => ['enabled' => true]]],
]);

it('registers the command listeners when the type is on', function () {
    $before = [count(Event::getListeners(CommandStarting::class)), count(Event::getListeners(CommandFinished::class))];
    bootRuntimeProject($this->app, ['enabled' => true, 'commands' => ['enabled' => true]]);

    expect([count(Event::getListeners(CommandStarting::class)), count(Event::getListeners(CommandFinished::class))])->toBe([$before[0] + 1, $before[1] + 1]);
});

it('records no command at sample rate 0 but still flushes the other aggregates when it finishes', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'commands' => ['enabled' => true, 'sampleRate' => 0], 'slowQueries' => ['thresholdMs' => 0]]);

    dispatchCommand('migrate', 0, fn () => DB::select('select 1'));

    expect(bufferedOfType($this->app, 'command'))->toHaveCount(0)
        ->and(bufferedOfType($this->app, 'slowQuery'))->toHaveCount(1);
});

it('never lets a failure in the command collector reach the host app', function () {
    bootRuntimeProject($this->app, ['enabled' => true, 'commands' => ['enabled' => true]]);
    $buffer = $this->app->storagePath('excubia/runtime');
    @mkdir(dirname($buffer), 0777, true);
    file_put_contents($buffer, 'not a directory');

    dispatchCommand('migrate', 2);

    expect(is_file($buffer))->toBeTrue();
});

/**
 * Laravel AI SDK runtime types (#198): `ai` and `aiTool`.
 */
const AI_MARKER = 'excubia-ai-marker-7f3a';

/**
 * Boot the Laravel AI SDK and Excubia with both AI types on, plus these keys.
 *
 * @param  array<string, mixed>  $runtime
 */
function bootAiRuntime(Application $app, array $runtime = []): void
{
    $app->register(AiServiceProvider::class);
    bootRuntimeProject($app, ['enabled' => true, 'ai' => ['enabled' => true], 'aiTools' => ['enabled' => true], ...$runtime]);
}

/**
 * A collector with both AI types on and a clock that moves 40 ms per read.
 */
function aiCollector(Application $app): RuntimeCollectorService
{
    $app->register(AiServiceProvider::class);
    $now = 1_800_000_000.0;

    return new RuntimeCollectorService(
        new RuntimeConfigurationData(isEnabled: true, enabledTypes: ['ai' => true, 'aiTool' => true]),
        new RuntimeBufferService($app->storagePath('excubia/runtime'), RuntimeConfigurationData::DEFAULT_BUFFER_MAX_BYTES),
        $app->basePath(),
        function () use (&$now): float {
            $now += 0.04;

            return $now;
        },
    );
}

function aiAgentPrompt(string $provider = 'openai', string $model = 'gpt-test'): AgentPrompt
{
    return new AgentPrompt(new FakeAiAgent(AI_MARKER), AI_MARKER, [], Ai::textProvider($provider), $model);
}

/**
 * Every usage field null, as on a failed attempt.
 *
 * @return array<string, null>
 */
function noAiUsage(): array
{
    return array_fill_keys(RuntimeBufferService::NULLABLE_SUM_FIELDS, null);
}

/**
 * @return list<int>
 */
function aiListenerCounts(): array
{
    return array_map(static fn (string $event): int => count(Event::getListeners($event)), [PromptingAgent::class, AgentPrompted::class, ProviderFailedOver::class, ToolInvoked::class]);
}

it('keeps the AI types off by default and registers their listeners only when switched on', function (array $runtime, array $added) {
    $this->app->register(AiServiceProvider::class);
    $before = aiListenerCounts();
    bootRuntimeProject($this->app, $runtime);

    expect(aiListenerCounts())->toBe(array_map(static fn (int $count, int $extra): int => $count + $extra, $before, $added));
})->with([
    'runtime on, types not switched on' => [['enabled' => true], [0, 0, 0, 0]],
    'runtime off' => [['enabled' => false, 'ai' => ['enabled' => true], 'aiTools' => ['enabled' => true]], [0, 0, 0, 0]],
    'ai only' => [['enabled' => true, 'ai' => ['enabled' => true]], [1, 1, 1, 0]],
    'aiTools only' => [['enabled' => true, 'aiTools' => ['enabled' => true]], [0, 0, 0, 1]],
]);

it('times an agent prompt from start to done by invocation id, with the provider and model of the response and its token usage', function () {
    bootRuntimeProject($this->app, ['enabled' => true]);
    $collector = aiCollector($this->app);
    $prompt = aiAgentPrompt();

    $collector->startAiOperation(new PromptingAgent('invocation-1', $prompt));
    $collector->recordAiOperation(new AgentPrompted('invocation-1', $prompt, new AgentResponse('invocation-1', AI_MARKER, new TextUsage(1200, 300, 800, 100, 50), new Meta('openai', 'gpt-test-2026-01-01'))));
    $collector->flush();

    expect(bufferedRuntime($this->app))->toHaveCount(1)
        ->and(bufferedRuntime($this->app)[0])->toEqual([
            'type' => 'ai',
            'bucket' => gmdate('Y-m-d\TH:i:00\Z', 1_800_000_000),
            'sampleRate' => 1.0,
            'operation' => 'agent',
            'agent' => FakeAiAgent::class,
            'provider' => 'openai',
            'model' => 'gpt-test-2026-01-01',
            'count' => 1,
            'errorCount' => 0,
            'totalMs' => 40.0,
            'maxMs' => 40.0,
            'histogram' => runtimeHistogram([3 => 1]),
            'failoverCount' => 0,
            'inputTokens' => 1200,
            'outputTokens' => 300,
            'cacheReadInputTokens' => 800,
            'cacheWriteInputTokens' => 100,
            'reasoningTokens' => 50,
            'imageInputTokens' => null,
            'imageOutputTokens' => null,
            'audioSeconds' => null,
            'searchUnits' => null,
        ]);
});

it('takes provider and model from the prompt when the response meta has none, and 0 ms when the start was not seen', function () {
    bootRuntimeProject($this->app, ['enabled' => true]);
    $collector = aiCollector($this->app);
    $prompt = aiAgentPrompt('anthropic', 'claude-test');

    $collector->recordAiOperation(new AgentPrompted('invocation-2', $prompt, new AgentResponse('invocation-2', AI_MARKER, new TextUsage(10, 2), new Meta)));
    $collector->flush();

    expect(bufferedRuntime($this->app)[0])->toMatchArray(['provider' => 'anthropic', 'model' => 'claude-test', 'totalMs' => 0.0, 'cacheReadInputTokens' => null, 'reasoningTokens' => null]);
});

it('forgets the oldest start past the pending invocation cap', function () {
    bootRuntimeProject($this->app, ['enabled' => true]);
    $collector = aiCollector($this->app);
    $prompt = aiAgentPrompt();

    for ($i = 0; $i <= RuntimeCollectorService::MAX_PENDING_AI_INVOCATIONS; $i++) {
        $collector->startAiOperation(new PromptingAgent('invocation-'.$i, $prompt));
    }

    $response = new AgentResponse('invocation-0', '', new TextUsage, new Meta);
    $collector->recordAiOperation(new AgentPrompted('invocation-0', $prompt, $response));
    $collector->recordAiOperation(new AgentPrompted('invocation-1', $prompt, $response));
    $collector->flush();

    // invocation-0 lost its start (0 ms); invocation-1 kept it, about 200 clock reads of 40 ms ago.
    expect(bufferedOfType($this->app, 'ai')[0])->toMatchArray(['count' => 2])
        ->and(bufferedOfType($this->app, 'ai')[0]['maxMs'])->toEqualWithDelta(8040.0, 0.01)
        ->and(bufferedOfType($this->app, 'ai')[0]['histogram'][0])->toBe(1);
});

it('records real agent runs, streams and tool calls through the SDK, a stream once with its final usage', function () {
    bootAiRuntime($this->app);
    FakeAiAgent::fake([
        new ToolCall('call-1', ToolNameResolver::resolve(new FakeAiTool), ['query' => AI_MARKER]),
        new TextResponse(AI_MARKER, new TextUsage(100, 20, 60), new Meta('openai', 'gpt-test')),
        new TextResponse(AI_MARKER, new TextUsage(40, 10), new Meta('openai', 'gpt-test')),
    ]);

    (new FakeAiAgent)->prompt(AI_MARKER, model: 'gpt-test');
    // A stream's meta is its prompt's provider and model.
    $stream = (new FakeAiAgent)->stream(AI_MARKER, model: 'gpt-test');

    foreach ($stream as $streamEvent) {
        // Read the whole stream: AgentStreamed comes at its end.
    }

    $this->app->terminate();
    $ai = bufferedOfType($this->app, 'ai');
    $tools = bufferedOfType($this->app, 'aiTool');

    expect($ai)->toHaveCount(1)
        ->and($ai[0])->toMatchArray([
            'operation' => 'agent',
            'agent' => FakeAiAgent::class,
            'provider' => 'openai',
            'model' => 'gpt-test',
            'count' => 2,
            'errorCount' => 0,
            'failoverCount' => 0,
            'inputTokens' => 140,
            'outputTokens' => 30,
            'cacheReadInputTokens' => 60,
            'cacheWriteInputTokens' => null,
        ])
        ->and(array_sum($ai[0]['histogram']))->toBe(2)
        ->and($tools)->toHaveCount(1)
        ->and($tools[0])->toMatchArray(['agent' => FakeAiAgent::class, 'tool' => FakeAiTool::class, 'count' => 1, 'errorCount' => 0])
        ->and($tools[0])->toHaveKeys(['totalMs', 'maxMs', 'histogram'])
        ->and(rawBuffer($this->app))->not->toContain(AI_MARKER);
});

it('counts an agent failover and a failed run per attempt, without usage or the exception message', function () {
    bootAiRuntime($this->app);
    FakeAiAgent::fake(function (string $prompt, $attachments, $provider) {
        if ($provider->name() === 'anthropic' || $prompt === 'fail') {
            throw new RateLimitedException(AI_MARKER);
        }

        return new TextResponse('ok', new TextUsage(10, 1), new Meta('openai', 'gpt-test'));
    });

    (new FakeAiAgent)->prompt('first', provider: ['anthropic' => 'claude-test', 'openai' => 'gpt-test']);

    expect(fn () => (new FakeAiAgent)->prompt('fail', provider: 'openai', model: 'gpt-test'))->toThrow(RateLimitedException::class);

    $this->app->terminate();
    $ai = bufferedOfType($this->app, 'ai')->keyBy('provider');

    expect($ai)->toHaveCount(2)
        ->and($ai['anthropic'])->toMatchArray(['operation' => 'agent', 'agent' => FakeAiAgent::class, 'model' => 'claude-test', 'count' => 1, 'errorCount' => 1, 'failoverCount' => 1, ...noAiUsage()])
        ->and($ai['openai'])->toMatchArray(['model' => 'gpt-test', 'count' => 2, 'errorCount' => 1, 'failoverCount' => 0, 'inputTokens' => 10, 'outputTokens' => 1])
        ->and(rawBuffer($this->app))->not->toContain(AI_MARKER);
});

it('counts a failed tool call by agent and tool, and the run it fails, without its arguments or exception', function () {
    bootAiRuntime($this->app);
    FakeAiAgent::fake([new ToolCall('call-1', ToolNameResolver::resolve(new FakeAiTool), ['query' => AI_MARKER, 'fail' => true]), 'done']);

    // The SDK rethrows the tool's exception, so the run fails too.
    expect(fn () => (new FakeAiAgent)->prompt(AI_MARKER))->toThrow(RuntimeException::class);

    $this->app->terminate();

    expect(bufferedOfType($this->app, 'aiTool')[0])->toMatchArray(['agent' => FakeAiAgent::class, 'tool' => FakeAiTool::class, 'count' => 1, 'errorCount' => 1])
        ->and(bufferedOfType($this->app, 'ai')[0])->toMatchArray(['count' => 1, 'errorCount' => 1])
        ->and(rawBuffer($this->app))->not->toContain(AI_MARKER);
});

it('records image, embeddings, transcription, reranking and audio with the usage each one reports', function () {
    bootAiRuntime($this->app);
    Image::fake(fn () => new ImageResponse(new Collection([new GeneratedImage(base64_encode('png'), 'image/png')]), new ImageUsage(5, 0, null, null, null, 30, 1290), new Meta('gemini', 'image-model')));
    Embeddings::fake(fn () => new EmbeddingsResponse([[0.1, 0.2]], new Usage(12), new Meta('openai', 'embedding-model')));
    Transcription::fake(fn () => new TranscriptionResponse(AI_MARKER, new Collection, new TranscriptionUsage(0, 0, null, null, null, 61.5), new Meta('openai', 'transcription-model')));
    Reranking::fake(fn () => new RerankingResponse([], new RerankingUsage(8, 1.0), new Meta('cohere', 'rerank-model')));
    Audio::fake(fn () => new AudioResponse(base64_encode('mp3'), new Usage(20), new Meta('openai', 'speech-model')));

    Image::of(AI_MARKER)->generate();
    Embeddings::for([AI_MARKER])->generate();
    Transcription::fromBase64(base64_encode(AI_MARKER), 'audio/mpeg')->generate();
    Reranking::of([AI_MARKER, 'other'])->rerank(AI_MARKER);
    Audio::of(AI_MARKER)->generate();
    $this->app->terminate();

    $ai = bufferedOfType($this->app, 'ai')->keyBy('operation');

    expect($ai->keys()->sort()->values()->all())->toBe(['audio', 'embeddings', 'image', 'reranking', 'transcription'])
        ->and($ai['image'])->toMatchArray(['provider' => 'gemini', 'count' => 1, 'errorCount' => 0, 'inputTokens' => 5, 'imageInputTokens' => 30, 'imageOutputTokens' => 1290, 'audioSeconds' => null])
        ->and($ai['image'])->not->toHaveKey('agent')
        ->and($ai['embeddings'])->toMatchArray(['provider' => 'openai', 'inputTokens' => 12, 'outputTokens' => 0, 'reasoningTokens' => null])
        ->and($ai['transcription'])->toMatchArray(['audioSeconds' => 61.5])
        ->and($ai['reranking'])->toMatchArray(['provider' => 'cohere', 'inputTokens' => 8, 'searchUnits' => 1.0])
        ->and($ai['audio'])->toMatchArray(['inputTokens' => 20, 'searchUnits' => null])
        ->and(rawBuffer($this->app))->not->toContain(AI_MARKER);
});

it('counts a failed image attempt and its failover on that attempt, then the success on the next provider', function () {
    bootAiRuntime($this->app);
    Image::fake(function (ImagePrompt $prompt) {
        if ($prompt->provider->name() === 'gemini') {
            throw new RateLimitedException(AI_MARKER);
        }

        return null;
    });

    Image::of(AI_MARKER)->generate(['gemini' => 'image-a', 'openai' => 'image-b']);
    Image::fake(fn () => throw new RuntimeException(AI_MARKER));

    expect(fn () => Image::of('again')->generate('openai', 'image-b'))->toThrow(RuntimeException::class);

    $this->app->terminate();
    $ai = bufferedOfType($this->app, 'ai')->keyBy('provider');

    expect($ai)->toHaveCount(2)
        ->and($ai['gemini'])->toMatchArray(['operation' => 'image', 'model' => 'image-a', 'count' => 1, 'errorCount' => 1, 'failoverCount' => 1, ...noAiUsage()])
        ->and($ai['openai'])->toMatchArray(['operation' => 'image', 'model' => 'image-b', 'count' => 2, 'errorCount' => 1, 'failoverCount' => 0])
        ->and(rawBuffer($this->app))->not->toContain(AI_MARKER);
});

it('adds a provider failover only to the failure it follows', function () {
    bootRuntimeProject($this->app, ['enabled' => true]);
    $collector = aiCollector($this->app);
    $provider = Ai::imageProvider('gemini');
    $prompt = new ImagePrompt(AI_MARKER, [], null, null, $provider, 'image-a');

    $collector->recordAiFailover(new ProviderFailedOver($provider, 'image-a', new RateLimitedException(AI_MARKER)));
    $collector->recordAiFailure(new ImageFailed('invocation-1', $provider, 'image-a', $prompt, new RuntimeException(AI_MARKER)));
    $collector->recordAiFailover(new ProviderFailedOver($provider, 'image-other', new RateLimitedException(AI_MARKER)));
    $collector->recordAiFailure(new ImageFailed('invocation-2', $provider, 'image-a', $prompt, new RuntimeException(AI_MARKER)));
    $collector->startAiOperation(new GeneratingImage('invocation-3', $provider, 'image-a', $prompt));
    $collector->recordAiFailover(new ProviderFailedOver($provider, 'image-a', new RateLimitedException(AI_MARKER)));
    $collector->flush();

    expect(bufferedOfType($this->app, 'ai')[0])->toMatchArray(['count' => 2, 'errorCount' => 2, 'failoverCount' => 0]);
});

it('times a classification from Classifying to Classified by invocation id, with its TextUsage and no agent', function () {
    bootRuntimeProject($this->app, ['enabled' => true]);
    $collector = aiCollector($this->app);
    $provider = Ai::classificationProvider('openai');
    $prompt = new ClassificationPrompt(AI_MARKER, ['mood' => new Choice(AI_MARKER, ['happy' => AI_MARKER, 'sad' => 'Sad'])], $provider, 'classifier-model');
    $response = new ClassificationResponse(['mood' => new ChoiceAnswer(AI_MARKER, [AI_MARKER => 1.0])], new TextUsage(120, 4, 20, 0, 2), new Meta('openai', 'classifier-model'));

    $collector->startAiOperation(new Classifying('invocation-1', $provider, 'classifier-model', $prompt));
    $collector->recordAiOperation(new Classified('invocation-1', $provider, 'classifier-model', $prompt, $response));
    $collector->flush();

    expect(bufferedRuntime($this->app))->toHaveCount(1)
        ->and(bufferedRuntime($this->app)[0])->toMatchArray([
            'type' => 'ai',
            'operation' => 'classification',
            'provider' => 'openai',
            'model' => 'classifier-model',
            'count' => 1,
            'errorCount' => 0,
            'failoverCount' => 0,
            'totalMs' => 40.0,
            'maxMs' => 40.0,
            'inputTokens' => 120,
            'outputTokens' => 4,
            'cacheReadInputTokens' => 20,
            'cacheWriteInputTokens' => 0,
            'reasoningTokens' => 2,
            'imageInputTokens' => null,
            'audioSeconds' => null,
            'searchUnits' => null,
        ])
        ->and(bufferedRuntime($this->app)[0])->not->toHaveKey('agent')
        ->and(rawBuffer($this->app))->not->toContain(AI_MARKER);
});

it('counts a failed classification attempt and its failover on that attempt, then the success on the next provider', function () {
    bootAiRuntime($this->app);
    Classification::fake(function (ClassificationPrompt $prompt) {
        if ($prompt->provider->name() === 'typesafe') {
            throw new RateLimitedException(AI_MARKER);
        }

        return new ClassificationResponse(['mood' => new ChoiceAnswer('happy', ['happy' => 0.9, 'sad' => 0.1])], new TextUsage(30, 2), new Meta('openai', 'gpt-classify'));
    });

    Classification::of(AI_MARKER)
        ->question('mood', new Choice(AI_MARKER, ['happy' => 'Happy', 'sad' => 'Sad']))
        ->classify(['typesafe' => 'classifier-a', 'openai' => 'gpt-classify']);
    $this->app->terminate();

    $ai = bufferedOfType($this->app, 'ai')->keyBy('provider');

    expect($ai)->toHaveCount(2)
        ->and($ai['typesafe'])->toMatchArray(['operation' => 'classification', 'model' => 'classifier-a', 'count' => 1, 'errorCount' => 1, 'failoverCount' => 1, ...noAiUsage()])
        ->and($ai['openai'])->toMatchArray(['operation' => 'classification', 'model' => 'gpt-classify', 'count' => 1, 'errorCount' => 0, 'failoverCount' => 0, 'inputTokens' => 30, 'outputTokens' => 2])
        ->and($ai['openai'])->not->toHaveKey('agent')
        ->and(rawBuffer($this->app))->not->toContain(AI_MARKER);
});

it('records no AI aggregate at sample rate 0', function () {
    bootAiRuntime($this->app, ['ai' => ['enabled' => true, 'sampleRate' => 0], 'aiTools' => ['enabled' => true, 'sampleRate' => 0]]);
    FakeAiAgent::fake([new ToolCall('call-1', ToolNameResolver::resolve(new FakeAiTool), ['query' => 'x']), 'done']);
    Image::fake();

    (new FakeAiAgent)->prompt('hello');
    Image::of('a cat')->generate();
    $this->app->terminate();

    expect(bufferedRuntime($this->app))->toBe([]);
});

it('never lets a failure in the AI collector reach the host app', function () {
    bootAiRuntime($this->app);
    $buffer = $this->app->storagePath('excubia/runtime');
    @mkdir(dirname($buffer), 0777, true);
    file_put_contents($buffer, 'not a directory');
    FakeAiAgent::fake(['done']);

    expect((new FakeAiAgent)->prompt('hello')->text)->toBe('done');

    $this->app->terminate();

    expect(is_file($buffer))->toBeTrue();
});
