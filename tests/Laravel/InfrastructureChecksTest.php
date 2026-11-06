<?php

declare(strict_types=1);

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Qiick\Excubia\Checks\LaravelCacheCheck;
use Qiick\Excubia\Checks\LaravelDatabaseCheck;
use Qiick\Excubia\Checks\LaravelDiskSpaceCheck;
use Qiick\Excubia\Checks\LaravelQueueBacklogCheck;
use Qiick\Excubia\Checks\LaravelQueueWorkersCheck;
use Qiick\Excubia\Checks\LaravelStorageCheck;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Contracts\DiskSpaceContract;
use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\HealthEndpointService;
use Qiick\Excubia\Laravel\LaravelPostureService;
use Qiick\Excubia\Laravel\QueueWorkerHeartbeatService;

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

    public function usage(string $directory): ?array
    {
        $this->measuredDirectory = $directory;

        return $this->usedPercent === null ? null : ['usedBytes' => (float) $this->usedPercent, 'totalBytes' => 100.0];
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

/**
 * Stand-in for Horizon's `MasterSupervisorRepository` and
 * `SupervisorRepository` contracts (Horizon is not a dependency): `all()`
 * answers the given records, or throws when the store is down.
 */
interface FakeHorizonRepository
{
    /**
     * @return list<object>
     */
    public function all(): array;
}

final class FakeHorizonRecords implements FakeHorizonRepository
{
    /**
     * @param  list<object>  $records
     */
    public function __construct(private readonly array $records, private readonly bool $isDown = false) {}

    public function all(): array
    {
        if ($this->isDown) {
            throw new RuntimeException('Connection refused [tcp://horizon-redis-sentinel:6379]');
        }

        return $this->records;
    }
}

const HORIZON_MASTER_REPOSITORY = 'Laravel\Horizon\Contracts\MasterSupervisorRepository';
const HORIZON_SUPERVISOR_REPOSITORY = 'Laravel\Horizon\Contracts\SupervisorRepository';

foreach ([HORIZON_MASTER_REPOSITORY, HORIZON_SUPERVISOR_REPOSITORY] as $horizonContract) {
    if (! interface_exists($horizonContract)) {
        class_alias(FakeHorizonRepository::class, $horizonContract);
    }
}

/**
 * Binds Horizon's repositories with masters of the given statuses and two
 * supervisors whose names carry the host prefix, as Horizon writes them.
 *
 * @param  list<string>  $masterStatuses
 */
function bindHorizon(array $masterStatuses, bool $isDown = false): void
{
    $masters = array_map(static fn (string $status): object => (object) ['name' => 'web-host-sentinel-ab12', 'pid' => 4242, 'status' => $status], $masterStatuses);
    $supervisors = $masterStatuses === [] ? [] : [
        (object) ['name' => 'web-host-sentinel-ab12:supervisor-1', 'pid' => 4243, 'processes' => ['redis:default' => 3, 'redis:emails' => 2]],
        (object) ['name' => 'web-host-sentinel-ab12:emails', 'pid' => 4244, 'processes' => ['redis:emails' => 1]],
    ];
    app()->instance(HORIZON_MASTER_REPOSITORY, new FakeHorizonRecords($masters, $isDown));
    app()->instance(HORIZON_SUPERVISOR_REPOSITORY, new FakeHorizonRecords($supervisors));
}

/**
 * The database queue connection, with a jobs table, as the default.
 */
function databaseQueueApp(): void
{
    infrastructureApp();
    config(['queue.connections.database' => ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default'], 'queue.default' => 'database']);
    createJobsTable();
}

function queueWorkersConfiguration(int $staleAfterMinutes): ExcubiaConfigurationData
{
    return (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => json_encode(['checks' => ['laravel-queue-workers' => ['staleAfterMinutes' => $staleAfterMinutes]]], JSON_THROW_ON_ERROR),
    ]));
}

describe('laravel-queue-workers', function () {
    beforeEach(fn () => QueueWorkerHeartbeatService::reset());

    it('is skipped without a posture', function () {
        $result = infrastructureResult(new LaravelQueueWorkersCheck, isBooted: false);

        expect($result->status->value)->toBe('skipped')
            ->and($result->findings[0]->message)->toContain('requires Artisan');
    });

    it('passes while Horizon is running', function () {
        infrastructureApp();
        bindHorizon(['running']);

        expect(infrastructureResult(new LaravelQueueWorkersCheck)->status->value)->toBe('pass');
    });

    it('warns at medium when Horizon is paused, with its processes and supervisor names without the host', function () {
        infrastructureApp();
        bindHorizon(['running', 'paused']);
        $result = infrastructureResult(new LaravelQueueWorkersCheck);

        expect($result->status->value)->toBe('warn')
            ->and($result->findings[0]->severity->value)->toBe('medium')
            ->and($result->findings[0]->subject)->toBe('horizon')
            ->and($result->findings[0]->message)->toBe('Laravel Horizon is paused: queued jobs are not processed.')
            ->and($result->findings[0]->details)->toBe(['status' => 'paused', 'processes' => 6, 'supervisors' => ['emails', 'supervisor-1']]);
    });

    it('fails at high when Horizon has no live master supervisor', function () {
        infrastructureApp();
        bindHorizon([]);
        $result = infrastructureResult(new LaravelQueueWorkersCheck);

        expect($result->status->value)->toBe('fail')
            ->and($result->findings[0]->severity->value)->toBe('high')
            ->and($result->findings[0]->details)->toBe(['status' => 'inactive', 'processes' => 0, 'supervisors' => []]);
    });

    it('is skipped when Horizon is installed but its store cannot be read', function () {
        infrastructureApp();
        bindHorizon(['running'], isDown: true);
        $result = infrastructureResult(new LaravelQueueWorkersCheck);

        expect($result->status->value)->toBe('skipped')
            ->and($result->findings[0]->message)->toBe('The status of Laravel Horizon could not be read.');
    });

    it('warns at medium per queue with pending jobs and a stale or missing heartbeat, and ignores idle queues', function () {
        databaseQueueApp();
        insertJob('default', time() - 60);
        insertJob('emails', time() - 60);
        insertJob('reports', time() - 60);
        cache()->forever(QueueWorkerHeartbeatService::cacheKey('database', 'default'), time() - 60);
        cache()->forever(QueueWorkerHeartbeatService::cacheKey('database', 'emails'), time() - 12 * 60);
        cache()->forever(QueueWorkerHeartbeatService::cacheKey('database', 'idle'), time() - 60 * 60);
        $result = infrastructureResult(new LaravelQueueWorkersCheck);

        expect($result->status->value)->toBe('warn')
            ->and(array_map(fn ($finding) => $finding->subject, $result->findings))->toBe(['emails', 'reports'])
            ->and($result->findings[0]->severity->value)->toBe('medium')
            ->and($result->findings[0]->message)->toBe('Queue emails has pending jobs and no worker has reported on it for 12 minutes.')
            ->and($result->findings[0]->details)->toBe(['connection' => 'database', 'queue' => 'emails', 'minutesSinceLastSeen' => 12])
            ->and($result->findings[1]->message)->toBe('Queue reports has pending jobs and no worker has ever reported on it.')
            ->and($result->findings[1]->details)->toBe(['connection' => 'database', 'queue' => 'reports', 'minutesSinceLastSeen' => null]);
    });

    it('reads checks.laravel-queue-workers.staleAfterMinutes', function () {
        databaseQueueApp();
        insertJob('default', time() - 60);
        cache()->forever(QueueWorkerHeartbeatService::cacheKey('database', 'default'), time() - 8 * 60);

        expect(infrastructureResult(new LaravelQueueWorkersCheck)->status->value)->toBe('warn')
            ->and(infrastructureResult(new LaravelQueueWorkersCheck, queueWorkersConfiguration(10))->status->value)->toBe('pass')
            ->and(ExcubiaConfigurationData::defaults()->queueWorkersStaleAfterMinutes)->toBe(5)
            ->and(fn () => queueWorkersConfiguration(0))->toThrow(InvalidConfigurationException::class, 'checks.laravel-queue-workers.staleAfterMinutes must be at least 1.');
    });

    it('reads the heartbeat of the Redis queues the backlog probe reads', function () {
        infrastructureApp();
        config(['queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default'], 'queue.default' => 'redis']);
        app()->instance('redis', new FakeQueueRedis(['queues:default' => ['{}'], 'queues:emails' => ['{}']]));
        cache()->forever(QueueWorkerHeartbeatService::cacheKey('redis', 'default'), time());
        $result = infrastructureResult(new LaravelQueueWorkersCheck, infrastructureConfiguration(queueNames: ['emails']));

        expect(array_map(fn ($finding) => $finding->details, $result->findings))->toBe([['connection' => 'redis', 'queue' => 'emails', 'minutesSinceLastSeen' => null]]);
    });

    it('is skipped for sync, unread drivers, unreadable stores and while no worker has ever reported', function () {
        infrastructureApp();
        $sync = infrastructureResult(new LaravelQueueWorkersCheck);
        config(['queue.connections.sqs' => ['driver' => 'sqs'], 'queue.default' => 'sqs']);
        $sqs = infrastructureResult(new LaravelQueueWorkersCheck);
        config(['queue.connections.redis' => ['driver' => 'redis'], 'queue.default' => 'redis']);
        app()->instance('redis', new FakeQueueRedis([], isDown: true));
        $redisDown = infrastructureResult(new LaravelQueueWorkersCheck);
        databaseQueueApp();
        insertJob('default', time() - 60);
        $neverReported = infrastructureResult(new LaravelQueueWorkersCheck);
        config(['cache.stores.broken' => ['driver' => 'database', 'connection' => 'testing', 'table' => 'missing_cache'], 'cache.default' => 'broken']);
        $cacheDown = infrastructureResult(new LaravelQueueWorkersCheck);

        expect($sync->findings[0]->message)->toBe('The default queue connection uses the sync driver, which runs jobs without a worker.')
            ->and($sqs->findings[0]->message)->toContain('uses sqs.')
            ->and($redisDown->findings[0]->message)->toBe('The queue store or the cache store holding the worker heartbeat could not be read.')
            ->and($neverReported->findings[0]->message)->toContain('No queue worker has reported a heartbeat yet.')
            ->and($cacheDown->findings[0]->message)->toBe('The queue store or the cache store holding the worker heartbeat could not be read.')
            ->and(array_map(fn ($result) => $result->status->value, [$sync, $sqs, $redisDown, $neverReported, $cacheDown]))->toBe(array_fill(0, 5, 'skipped'));
    });

    it('writes the heartbeat of each worker queue at most once a minute', function () {
        infrastructureApp();
        $now = 1_800_000_000;
        QueueWorkerHeartbeatService::record(app(), 'redis', 'high,default', $now);
        QueueWorkerHeartbeatService::record(app(), 'redis', 'high', $now + 59);
        $throttled = [cache()->get('excubia:worker-heartbeat:redis:high'), cache()->get('excubia:worker-heartbeat:redis:default')];
        QueueWorkerHeartbeatService::record(app(), 'redis', 'high', $now + 60);

        expect($throttled)->toBe([$now, $now])
            ->and(cache()->get('excubia:worker-heartbeat:redis:high'))->toBe($now + 60)
            ->and(cache()->get('excubia:worker-heartbeat:redis:default'))->toBe($now);
    });

    it('swallows a cache failure while writing the heartbeat', function () {
        infrastructureApp();
        config(['cache.stores.broken' => ['driver' => 'database', 'connection' => 'testing', 'table' => 'missing_cache'], 'cache.default' => 'broken']);

        expect(fn () => QueueWorkerHeartbeatService::record(app(), 'redis', 'default', time()))->not->toThrow(Throwable::class);
    });

    it('listens to Looping only once a queue worker is built, and writes the heartbeat from the event', function () {
        infrastructureApp();
        $before = count(Event::getListeners(Looping::class));
        app('queue.worker');
        app('queue.worker');
        $after = count(Event::getListeners(Looping::class));
        event(new Looping('database', 'emails'));

        expect($after)->toBe($before + 1)
            ->and(cache()->get('excubia:worker-heartbeat:database:emails'))->toBeInt();
    });

    it('keeps hostnames, PIDs and payloads out of the queue workers findings', function () {
        infrastructureApp();
        config(['queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default', 'host' => 'redis-host-sentinel'], 'queue.default' => 'redis']);
        bindHorizon(['paused']);

        Artisan::call('excubia:check', ['--format' => 'json', '--no-banner' => true, '--fail-on' => 'none', '--only' => 'laravel-queue-workers']);
        $output = Artisan::output();

        expect($output)->toContain('Laravel Horizon is paused', '"supervisor-1"')
            ->not->toContain('sentinel')
            ->not->toContain('4243')
            ->not->toContain('4242');
    });
});
