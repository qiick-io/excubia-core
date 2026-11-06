<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Excubia\Checks\LaravelCacheCheck;
use Qiick\Excubia\Checks\LaravelDatabaseCheck;
use Qiick\Excubia\Checks\LaravelDiskSpaceCheck;
use Qiick\Excubia\Checks\LaravelQueueBacklogCheck;
use Qiick\Excubia\Checks\LaravelStorageCheck;
use Qiick\Excubia\Contracts\DiskSpaceContract;
use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\HealthEndpointService;
use Qiick\Excubia\Laravel\LaravelPostureService;

/**
 * Disk measurement stand-in: always answers `$usedPercent`.
 */
final class FakeDiskSpace implements DiskSpaceContract
{
    public ?string $measuredDirectory = null;

    public function __construct(private readonly ?int $usedPercent) {}

    public function usedPercent(string $directory): ?int
    {
        $this->measuredDirectory = $directory;

        return $this->usedPercent;
    }
}

/**
 * Redis stand-in for the queue backlog probe: `queues:<name>` lists only.
 */
final class FakeQueueRedis
{
    /**
     * @param  array<string, list<string>>  $lists
     */
    public function __construct(public array $lists, public bool $isDown = false) {}

    public function connection(?string $name = null): self
    {
        if ($this->isDown) {
            throw new RuntimeException('Connection refused [tcp://redis-host-sentinel:6379]');
        }

        return $this;
    }

    public function llen(string $key): int
    {
        return count($this->lists[$key] ?? []);
    }

    public function lindex(string $key, int $index): ?string
    {
        return $this->lists[$key][$index] ?? null;
    }
}

/**
 * An app rooted in a temporary project with an in-memory SQLite default
 * connection, an array cache and a sync queue.
 *
 * @param  array<string, string>  $files
 */
function infrastructureApp(array $files = []): string
{
    $project = temporaryProject($files + [
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [], "packages-dev": []}',
        'bootstrap/cache/.gitkeep' => '',
        'storage/framework/.gitkeep' => '',
    ]);
    app()->setBasePath($project);
    app()->useBootstrapPath($project.'/bootstrap');
    app()->useStoragePath($project.'/storage');
    config([
        'database.default' => 'testing',
        'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'cache.default' => 'array',
        'queue.default' => 'sync',
    ]);

    return $project;
}

function infrastructureResult(HealthCheckContract $check, ?ExcubiaConfigurationData $configuration = null, ?DiskSpaceContract $diskSpace = null, bool $isBooted = true): CheckResultData
{
    $configuration ??= ExcubiaConfigurationData::defaults();
    $snapshot = new ProjectSnapshotData(null, null, [], [], null);

    if ($isBooted) {
        $snapshot = $snapshot->withLaravelPosture((new LaravelPostureService($diskSpace ?? new FakeDiskSpace(10)))->collect(app(), configuration: $configuration));
    }

    return $check->run($snapshot, $configuration);
}

/**
 * The defaults with some infrastructure settings changed.
 *
 * @param  list<string>  $databaseConnectionNames
 * @param  list<string>  $queueNames
 */
function infrastructureConfiguration(array $databaseConnectionNames = [], int $warnAt = 80, int $failAt = 90, int $maxAgeMinutes = 15, int $maxPending = 1000, array $queueNames = []): ExcubiaConfigurationData
{
    $defaults = ExcubiaConfigurationData::defaults();

    return new ExcubiaConfigurationData(
        failOnSeverity: $defaults->failOnSeverity,
        isDevelopmentDependencyIncluded: true,
        staleAfterMonths: 24,
        phpWarnDaysBeforeEndOfLife: 180,
        laravelWarnDaysBeforeEndOfLife: 180,
        isNodeEndOfLifeCheckEnabled: true,
        ignoreRules: [],
        cacheDirectory: null,
        cacheTtlHours: 24,
        databaseConnectionNames: $databaseConnectionNames,
        diskSpaceWarnAtPercentUsed: $warnAt,
        diskSpaceFailAtPercentUsed: $failAt,
        queueBacklogMaxOldestAgeMinutes: $maxAgeMinutes,
        queueBacklogMaxPendingJobs: $maxPending,
        queueBacklogQueueNames: $queueNames,
    );
}

function createJobsTable(): void
{
    Schema::create('jobs', function ($table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

function insertJob(string $queue, int $availableAt, ?int $reservedAt = null): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => '{"secret":"payload-sentinel"}',
        'attempts' => 0,
        'reserved_at' => $reservedAt,
        'available_at' => $availableAt,
        'created_at' => $availableAt,
    ]);
}

it('reports every infrastructure check as skipped when the snapshot has no posture', function (HealthCheckContract $check) {
    $result = infrastructureResult($check, isBooted: false);

    expect($result->status->value)->toBe('skipped')
        ->and($result->findings[0]->message)->toContain('requires Artisan');
})->with([
    'database' => fn () => new LaravelDatabaseCheck,
    'cache' => fn () => new LaravelCacheCheck,
    'storage' => fn () => new LaravelStorageCheck,
    'disk space' => fn () => new LaravelDiskSpaceCheck,
    'queue backlog' => fn () => new LaravelQueueBacklogCheck,
]);

describe('laravel-database', function () {
    it('passes when the default connection answers', function () {
        infrastructureApp();

        expect(infrastructureResult(new LaravelDatabaseCheck)->status->value)->toBe('pass');
    });

    it('fails at critical for the default or a listed connection that does not answer, and for an unknown name', function () {
        $project = infrastructureApp();
        config(['database.connections.broken' => ['driver' => 'sqlite', 'database' => $project.'/missing/db.sqlite', 'prefix' => '']]);
        $result = infrastructureResult(new LaravelDatabaseCheck, infrastructureConfiguration(databaseConnectionNames: ['broken', 'testing', 'nowhere']));

        expect($result->status->value)->toBe('fail')
            ->and(array_map(fn ($finding) => $finding->subject, $result->findings))->toBe(['broken', 'nowhere'])
            ->and($result->findings[0]->severity->value)->toBe('critical')
            ->and($result->findings[0]->message)->toBe('The database connection broken did not answer a trivial query (unreachable, refused or not configured).')
            ->and($result->findings[0]->details)->toBe(['connection' => 'broken']);
    });

    it('opens a MySQL connection with a short timeout for the probe, then closes it and restores its settings', function () {
        infrastructureApp();
        $settings = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'none', 'username' => 'none', 'password' => '', 'options' => []];
        config(['database.connections.replica' => $settings]);
        $result = infrastructureResult(new LaravelDatabaseCheck, infrastructureConfiguration(databaseConnectionNames: ['replica']));

        expect(array_map(fn ($finding) => $finding->subject, $result->findings))->toBe(['replica'])
            ->and(config('database.connections.replica'))->toBe($settings)
            ->and(array_keys(DB::getConnections()))->not->toContain('replica');
    });

    it('is skipped when no database connection is configured', function () {
        infrastructureApp();
        config(['database.default' => null]);

        expect(infrastructureResult(new LaravelDatabaseCheck)->findings[0]->message)->toBe('No database connection is configured.');
    });

    it('reads the extra connections from excubia.json through excubia:check', function () {
        infrastructureApp(['excubia.json' => '{"checks": {"laravel-database": {"connections": ["nowhere"]}}}']);

        Artisan::call('excubia:check', ['--format' => 'json', '--no-banner' => true, '--fail-on' => 'none', '--only' => 'laravel-database']);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        expect(array_column($report['checks'][0]['findings'], 'subject'))->toBe(['nowhere']);
    });

    it('probes with the defaults when excubia.json is invalid, and the command reports the error', function () {
        infrastructureApp(['excubia.json' => '{"checks": {"laravel-database": {"connections": "nowhere"}}}']);

        expect(Artisan::call('excubia:check', ['--format' => 'json', '--no-banner' => true]))->toBe(2);
    });
});

describe('laravel-cache', function () {
    it('passes when the default store writes, reads and deletes the probe key, and leaves nothing behind', function () {
        infrastructureApp();
        $result = infrastructureResult(new LaravelCacheCheck);

        expect($result->status->value)->toBe('pass')
            ->and(cache()->store('array')->getStore()->all())->toBe([]);
    });

    it('fails at high when the default store cannot store the probe key', function () {
        $project = infrastructureApp();
        config([
            'database.connections.cache-broken' => ['driver' => 'sqlite', 'database' => $project.'/missing/cache.sqlite', 'prefix' => ''],
            'cache.stores.shared' => ['driver' => 'database', 'connection' => 'cache-broken', 'table' => 'cache'],
            'cache.default' => 'shared',
        ]);
        $finding = infrastructureResult(new LaravelCacheCheck)->findings[0];

        expect($finding->status->value)->toBe('fail')
            ->and($finding->severity->value)->toBe('high')
            ->and($finding->subject)->toBe('shared')
            ->and($finding->details)->toBe(['store' => 'shared']);
    });

    it('is skipped with the null driver or without a default store', function () {
        infrastructureApp();
        config(['cache.stores.none' => ['driver' => 'null'], 'cache.default' => 'none']);
        $nullDriver = infrastructureResult(new LaravelCacheCheck);
        config(['cache.default' => null]);
        $noStore = infrastructureResult(new LaravelCacheCheck);

        expect($nullDriver->status->value)->toBe('skipped')
            ->and($nullDriver->findings[0]->message)->toContain('null driver')
            ->and($noStore->findings[0]->message)->toBe('No default cache store is configured.');
    });
});

describe('laravel-storage', function () {
    it('passes when storage and bootstrap/cache are writable', function () {
        infrastructureApp();

        expect(infrastructureResult(new LaravelStorageCheck)->status->value)->toBe('pass');
    });

    it('fails at high for each directory that is missing or read-only', function () {
        $project = infrastructureApp();
        unlink($project.'/bootstrap/cache/.gitkeep');
        rmdir($project.'/bootstrap/cache');
        chmod($project.'/storage', 0555);
        $result = infrastructureResult(new LaravelStorageCheck);
        chmod($project.'/storage', 0755);

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            expect(array_map(fn ($finding) => $finding->subject, $result->findings))->toBe(['bootstrap/cache']);

            return;
        }

        expect($result->status->value)->toBe('fail')
            ->and(array_map(fn ($finding) => $finding->subject, $result->findings))->toBe(['storage', 'bootstrap/cache'])
            ->and($result->findings[0]->severity->value)->toBe('high')
            ->and($result->findings[1]->details)->toBe(['directory' => 'bootstrap/cache']);
    });
});

describe('laravel-disk-space', function () {
    it('measures the disk holding storage/ and passes below the warn threshold', function () {
        $project = infrastructureApp();
        $diskSpace = new FakeDiskSpace(79);

        expect(infrastructureResult(new LaravelDiskSpaceCheck, diskSpace: $diskSpace)->status->value)->toBe('pass')
            ->and($diskSpace->measuredDirectory)->toBe($project.'/storage');
    });

    it('warns at medium from 80 % and fails at high from 90 %', function (int $usedPercent, string $status, string $severity) {
        infrastructureApp();
        $finding = infrastructureResult(new LaravelDiskSpaceCheck, diskSpace: new FakeDiskSpace($usedPercent))->findings[0];

        expect($finding->status->value)->toBe($status)
            ->and($finding->severity->value)->toBe($severity)
            ->and($finding->subject)->toBe('disk')
            ->and($finding->message)->toBe('The disk holding storage/ is '.$usedPercent.' % full.')
            ->and($finding->details)->toBe(['usedPercent' => $usedPercent]);
    })->with([
        'at warn' => [80, 'warn', 'medium'],
        'between' => [89, 'warn', 'medium'],
        'at fail' => [90, 'fail', 'high'],
        'full' => [100, 'fail', 'high'],
    ]);

    it('uses the configured thresholds', function () {
        infrastructureApp();
        $configuration = infrastructureConfiguration(warnAt: 50, failAt: 60);

        expect(infrastructureResult(new LaravelDiskSpaceCheck, $configuration, new FakeDiskSpace(55))->status->value)->toBe('warn')
            ->and(infrastructureResult(new LaravelDiskSpaceCheck, $configuration, new FakeDiskSpace(60))->status->value)->toBe('fail')
            ->and(infrastructureResult(new LaravelDiskSpaceCheck, $configuration, new FakeDiskSpace(49))->status->value)->toBe('pass');
    });

    it('is skipped when the disk cannot be measured', function () {
        infrastructureApp();
        $result = infrastructureResult(new LaravelDiskSpaceCheck, diskSpace: new FakeDiskSpace(null));

        expect($result->status->value)->toBe('skipped')
            ->and($result->findings[0]->message)->toBe('The disk holding storage/ could not be measured.');
    });
});

describe('laravel-queue-backlog', function () {
    it('passes for the database driver when every queue is within the thresholds, and ignores reserved and delayed jobs', function () {
        infrastructureApp();
        config(['queue.connections.database' => ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default'], 'queue.default' => 'database']);
        createJobsTable();
        insertJob('default', time() - 60);
        insertJob('default', time() - 3600, reservedAt: time());
        insertJob('emails', time() + 3600);

        expect(infrastructureResult(new LaravelQueueBacklogCheck)->status->value)->toBe('pass');
    });

    it('warns at medium per database queue whose oldest job is too old or that holds too many jobs', function () {
        infrastructureApp();
        config(['queue.connections.database' => ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default'], 'queue.default' => 'database']);
        createJobsTable();
        insertJob('default', time() - 20 * 60);
        insertJob('emails', time() - 60);
        insertJob('emails', time() - 60);
        insertJob('reports', time() - 60);
        $result = infrastructureResult(new LaravelQueueBacklogCheck, infrastructureConfiguration(maxPending: 1));

        expect($result->status->value)->toBe('warn')
            ->and(array_map(fn ($finding) => $finding->subject, $result->findings))->toBe(['default', 'emails'])
            ->and($result->findings[0]->severity->value)->toBe('medium')
            ->and($result->findings[0]->message)->toBe('Queue default holds 1 pending job. The oldest has waited 20 minutes.')
            ->and($result->findings[1]->details)->toBe(['queue' => 'emails', 'pendingCount' => 2, 'oldestAgeMinutes' => 1]);
    });

    it('reads the default and the configured queues on Redis from the head job createdAt', function () {
        infrastructureApp();
        config(['queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default'], 'queue.default' => 'redis']);
        app()->instance('redis', new FakeQueueRedis([
            'queues:default' => [json_encode(['createdAt' => time() - 30 * 60]), json_encode(['createdAt' => time()])],
            'queues:emails' => ['{"no-created-at": true}', '{}', '{}'],
            'queues:ignored' => array_fill(0, 5, '{}'),
        ]));
        $result = infrastructureResult(new LaravelQueueBacklogCheck, infrastructureConfiguration(maxPending: 2, queueNames: ['emails', 'idle']));

        expect(array_map(fn ($finding) => $finding->subject, $result->findings))->toBe(['default', 'emails'])
            ->and($result->findings[0]->details)->toBe(['queue' => 'default', 'pendingCount' => 2, 'oldestAgeMinutes' => 30])
            ->and($result->findings[1]->details)->toBe(['queue' => 'emails', 'pendingCount' => 3, 'oldestAgeMinutes' => null])
            ->and($result->findings[1]->message)->toBe('Queue emails holds 3 pending jobs.');
    });

    it('is skipped for other drivers and when the queue store cannot be read', function () {
        infrastructureApp();
        $sync = infrastructureResult(new LaravelQueueBacklogCheck);
        config(['queue.connections.database' => ['driver' => 'database', 'connection' => 'testing', 'table' => 'missing_jobs'], 'queue.default' => 'database']);
        $missingTable = infrastructureResult(new LaravelQueueBacklogCheck);
        config(['queue.connections.redis' => ['driver' => 'redis'], 'queue.default' => 'redis']);
        app()->instance('redis', new FakeQueueRedis([], isDown: true));
        $redisDown = infrastructureResult(new LaravelQueueBacklogCheck);
        config(['queue.default' => 'undefined']);
        $undefined = infrastructureResult(new LaravelQueueBacklogCheck);

        expect($sync->status->value)->toBe('skipped')
            ->and($sync->findings[0]->message)->toContain('uses sync.')
            ->and($missingTable->findings[0]->message)->toBe('The queue store of the default queue connection could not be read.')
            ->and($redisDown->findings[0]->message)->toBe('The queue store of the default queue connection could not be read.')
            ->and($undefined->findings[0]->message)->toContain('uses an unknown driver.');
    });
});

it('keeps hosts, DSNs, credentials and paths out of every infrastructure finding', function () {
    $project = infrastructureApp([
        'excubia.json' => '{"checks": {"laravel-database": {"connections": ["primary"]}}}',
    ]);
    config([
        'database.connections.primary' => ['driver' => 'sqlite', 'database' => $project.'/missing-dir-sentinel/db.sqlite', 'prefix' => '', 'password' => 'db-password-sentinel'],
        'database.connections.cache-db' => ['driver' => 'sqlite', 'database' => $project.'/cache-path-sentinel/cache.sqlite', 'prefix' => ''],
        'cache.stores.shared' => ['driver' => 'database', 'connection' => 'cache-db', 'table' => 'cache'],
        'cache.default' => 'shared',
        'queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default', 'host' => 'redis-host-sentinel'],
        'queue.default' => 'redis',
    ]);
    app()->instance('redis', new FakeQueueRedis(['queues:default' => array_fill(0, 3, '{"createdAt": 1, "secret": "payload-sentinel"}')]));
    unlink($project.'/bootstrap/cache/.gitkeep');
    rmdir($project.'/bootstrap/cache');

    Artisan::call('excubia:check', [
        '--format' => 'json',
        '--no-banner' => true,
        '--fail-on' => 'none',
        '--only' => 'laravel-database,laravel-cache,laravel-storage,laravel-disk-space,laravel-queue-backlog,php-platform-requirements',
    ]);
    $output = Artisan::output();

    expect($output)->toContain('The database connection primary did not answer', 'The cache store shared could not write', 'bootstrap/cache is not writable', 'Queue default holds 3 pending jobs')
        ->not->toContain('sentinel')
        ->not->toContain($project)
        ->not->toContain(sys_get_temp_dir())
        ->not->toContain(base_path());
});

it('maps an infrastructure check into the health endpoint', function () {
    $secret = str_repeat('h', HealthEndpointService::MIN_SECRET_LENGTH);
    putenv(HealthEndpointService::SECRET_VARIABLE.'='.$secret);
    $project = infrastructureApp([
        'excubia.json' => json_encode(['healthEndpoint' => ['enabled' => true], 'cache' => ['directory' => temporaryProject([])]], JSON_THROW_ON_ERROR),
    ]);
    config(['database.connections.testing.database' => $project.'/missing-dir/db.sqlite']);
    DB::purge('testing');
    app()->register(ExcubiaServiceProvider::class, true);

    $this->artisan('excubia:check', ['--only' => 'laravel-database,laravel-storage', '--fail-on' => 'none', '--no-banner' => true])->assertExitCode(0);
    $response = $this->get('/excubia/health', [HealthEndpointService::SECRET_HEADER => $secret]);
    putenv(HealthEndpointService::SECRET_VARIABLE);

    $response->assertOk()
        ->assertJsonPath('checkResults.0.name', 'laravel-database')
        ->assertJsonPath('checkResults.0.label', 'Database')
        ->assertJsonPath('checkResults.0.status', 'failed')
        ->assertJsonPath('checkResults.0.notificationMessage', '1 finding (highest severity: critical): testing.')
        ->assertJsonPath('checkResults.0.meta.highestSeverity', 'critical')
        ->assertJsonPath('checkResults.1.name', 'laravel-storage')
        ->assertJsonPath('checkResults.1.status', 'ok');
    expect($response->getContent())->not->toContain($project);
});
