<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Excubia\Checks\LaravelDebugModeCheck;
use Qiick\Excubia\Checks\LaravelFailedJobsCheck;
use Qiick\Excubia\Checks\LaravelMaintenanceModeCheck;
use Qiick\Excubia\Checks\LaravelPendingMigrationsCheck;
use Qiick\Excubia\Checks\LaravelProductionCachesCheck;
use Qiick\Excubia\Checks\LaravelSchedulerHeartbeatCheck;
use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Laravel\ExcubiaHeartbeatCommand;
use Qiick\Excubia\Laravel\LaravelPostureService;

/**
 * An app rooted in a temporary project, so cache files, migrations and the
 * maintenance file live there and not in the Testbench skeleton.
 *
 * @param  array<string, string>  $files
 */
function postureApp(array $files = []): string
{
    $project = temporaryProject($files + [
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [], "packages-dev": []}',
        'bootstrap/cache/.gitkeep' => '',
        'storage/framework/.gitkeep' => '',
        'database/migrations/.gitkeep' => '',
    ]);
    app()->setBasePath($project);
    app()->useBootstrapPath($project.'/bootstrap');
    app()->useStoragePath($project.'/storage');
    app()->useDatabasePath($project.'/database');
    config([
        'database.default' => 'testing',
        'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'queue.failed' => ['driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs'],
    ]);
    app()->forgetInstance('queue.failer');
    // Recent Laravel versions remember the cache state once read; read the files again.
    app()->forgetInstance('config_loaded_from_cache');
    app()->forgetInstance('routes.cached');
    app()->forgetInstance('events.cached');

    return $project;
}

function migrationFile(string $table): string
{
    return <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
            \$table->id();
        });
    }
};
PHP;
}

function postureResult(HealthCheckContract $check, ?ExcubiaConfigurationData $configuration = null, bool $isBooted = true): CheckResultData
{
    $snapshot = new ProjectSnapshotData(null, null, [], [], null);

    if ($isBooted) {
        $snapshot = $snapshot->withLaravelPosture((new LaravelPostureService)->collect(app()));
    }

    return $check->run($snapshot, $configuration ?? ExcubiaConfigurationData::defaults());
}

function createFailedJobsTable(): void
{
    Schema::create('failed_jobs', function ($table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
}

function insertFailedJob(string $failedAt): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => bin2hex(random_bytes(8)),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => '{"secret":"payload-sentinel"}',
        'exception' => 'RuntimeException: exception-sentinel',
        'failed_at' => $failedAt,
    ]);
}

it('reports every posture check as skipped when the snapshot has no posture', function (HealthCheckContract $check) {
    $result = postureResult($check, isBooted: false);

    expect($result->status->value)->toBe('skipped')
        ->and($result->findings[0]->message)->toContain('requires Artisan');
})->with([
    'debug mode' => fn () => new LaravelDebugModeCheck,
    'production caches' => fn () => new LaravelProductionCachesCheck,
    'pending migrations' => fn () => new LaravelPendingMigrationsCheck,
    'failed jobs' => fn () => new LaravelFailedJobsCheck,
    'scheduler heartbeat' => fn () => new LaravelSchedulerHeartbeatCheck,
    'maintenance mode' => fn () => new LaravelMaintenanceModeCheck,
]);

describe('laravel-debug-mode', function () {
    it('passes when debug is off or the environment is local or testing', function (string $environment, bool $isDebug) {
        postureApp();
        app()['env'] = $environment;
        config(['app.debug' => $isDebug]);

        expect(postureResult(new LaravelDebugModeCheck)->status->value)->toBe('pass');
    })->with([
        'production, debug off' => ['production', false],
        'local, debug on' => ['local', true],
        'testing, debug on' => ['testing', true],
    ]);

    it('fails at high when debug is on in production', function () {
        postureApp();
        app()['env'] = 'production';
        config(['app.debug' => true]);
        $finding = postureResult(new LaravelDebugModeCheck)->findings[0];

        expect($finding->status->value)->toBe('fail')
            ->and($finding->severity->value)->toBe('high')
            ->and($finding->subject)->toBe('app.debug');
    });
});

describe('laravel-production-caches', function () {
    it('passes in production when config, route and event caches exist', function () {
        postureApp([
            'bootstrap/cache/config.php' => '<?php return [];',
            'bootstrap/cache/routes-v7.php' => '<?php',
            'bootstrap/cache/events.php' => '<?php return [];',
        ]);
        app()['env'] = 'production';

        expect(postureResult(new LaravelProductionCachesCheck)->status->value)->toBe('pass');
    });

    it('warns at low for each missing cache in production', function () {
        postureApp(['bootstrap/cache/config.php' => '<?php return [];']);
        app()['env'] = 'production';
        $result = postureResult(new LaravelProductionCachesCheck);

        expect($result->status->value)->toBe('warn')
            ->and(array_map(fn ($finding) => $finding->subject, $result->findings))->toBe(['route-cache', 'event-cache'])
            ->and($result->findings[0]->severity->value)->toBe('low');
    });

    it('is skipped outside production', function () {
        postureApp();

        expect(postureResult(new LaravelProductionCachesCheck)->status->value)->toBe('skipped');
    });
});

describe('laravel-pending-migrations', function () {
    it('fails at medium while migrations are not run, and passes after migrate', function () {
        postureApp([
            'database/migrations/2026_01_01_000000_create_widgets_table.php' => migrationFile('widgets'),
            'database/migrations/2026_01_02_000000_create_gadgets_table.php' => migrationFile('gadgets'),
        ]);
        $pending = postureResult(new LaravelPendingMigrationsCheck)->findings[0];

        expect($pending->status->value)->toBe('fail')
            ->and($pending->severity->value)->toBe('medium')
            ->and($pending->message)->toBe('2 pending migrations: 2026_01_01_000000_create_widgets_table, 2026_01_02_000000_create_gadgets_table.');

        Artisan::call('migrate', ['--force' => true]);

        expect(postureResult(new LaravelPendingMigrationsCheck)->status->value)->toBe('pass');
    });

    it('is skipped when the database cannot be read', function () {
        postureApp(['database/migrations/2026_01_01_000000_create_widgets_table.php' => migrationFile('widgets')]);
        config(['database.connections.testing.database' => '/excubia-missing-directory/database.sqlite']);
        DB::purge('testing');

        expect(postureResult(new LaravelPendingMigrationsCheck)->status->value)->toBe('skipped');
    });
});

describe('laravel-failed-jobs', function () {
    it('passes when the failed job table is empty', function () {
        postureApp();
        createFailedJobsTable();

        expect(postureResult(new LaravelFailedJobsCheck)->status->value)->toBe('pass');
    });

    it('warns at medium for recent failed jobs and at high when the oldest passes the threshold', function () {
        postureApp();
        createFailedJobsTable();
        insertFailedJob(date('Y-m-d H:i:s', time() - 2 * 3600));
        $recent = postureResult(new LaravelFailedJobsCheck)->findings[0];
        insertFailedJob(date('Y-m-d H:i:s', time() - 30 * 3600));
        $old = postureResult(new LaravelFailedJobsCheck)->findings[0];

        expect($recent->status->value)->toBe('warn')
            ->and($recent->severity->value)->toBe('medium')
            ->and($recent->message)->toBe('1 failed job. The oldest failed 2 hours ago.')
            ->and($old->severity->value)->toBe('high')
            ->and($old->message)->toBe('2 failed jobs. The oldest failed 30 hours ago.');
    });

    it('uses the highSeverityAfterHours threshold from the configuration', function () {
        postureApp();
        createFailedJobsTable();
        insertFailedJob(date('Y-m-d H:i:s', time() - 2 * 3600));
        $configuration = new ExcubiaConfigurationData(...[
            ...get_object_vars(ExcubiaConfigurationData::defaults()),
            'failedJobHighSeverityAfterHours' => 1,
        ]);

        expect(postureResult(new LaravelFailedJobsCheck, $configuration)->findings[0]->severity->value)->toBe('high');
    });

    it('is skipped with the null failed job driver', function () {
        postureApp();
        config(['queue.failed.driver' => 'null']);
        app()->forgetInstance('queue.failer');

        expect(postureResult(new LaravelFailedJobsCheck)->status->value)->toBe('skipped');
    });
});

describe('laravel-scheduler-heartbeat', function () {
    it('passes right after excubia:heartbeat ran', function () {
        postureApp();
        Artisan::call('excubia:heartbeat');

        expect(postureResult(new LaravelSchedulerHeartbeatCheck)->status->value)->toBe('pass');
    });

    it('warns at medium when the heartbeat is missing or older than the threshold', function () {
        postureApp();
        $never = postureResult(new LaravelSchedulerHeartbeatCheck)->findings[0];
        cache()->forever(ExcubiaHeartbeatCommand::CACHE_KEY, time() - 15 * 60);
        $stale = postureResult(new LaravelSchedulerHeartbeatCheck)->findings[0];

        expect($never->status->value)->toBe('warn')
            ->and($never->severity->value)->toBe('medium')
            ->and($never->message)->toBe('The scheduler has never run excubia:heartbeat.')
            ->and($stale->message)->toBe('The scheduler last ran 15 minutes ago.');
    });

    it('is skipped when the cache store cannot be read', function () {
        postureApp();
        app()->instance('cache', new class
        {
            public function get(string $key): never
            {
                throw new RuntimeException('redis://excubia-sentinel-host:6379 refused');
            }
        });

        $result = postureResult(new LaravelSchedulerHeartbeatCheck);

        expect($result->status->value)->toBe('skipped')
            ->and($result->findings[0]->message)->not->toContain('sentinel');
    });

    it('is scheduled every minute by the service provider', function () {
        $events = app(Schedule::class)->events();
        $heartbeat = array_values(array_filter($events, fn ($event) => str_contains((string) $event->command, 'excubia:heartbeat')));

        expect($heartbeat)->toHaveCount(1)
            ->and($heartbeat[0]->expression)->toBe('* * * * *');
    });
});

describe('laravel-maintenance-mode', function () {
    it('warns at medium while the app is down and passes after up', function () {
        postureApp();
        Artisan::call('down');
        $down = postureResult(new LaravelMaintenanceModeCheck)->findings[0];
        Artisan::call('up');

        expect($down->status->value)->toBe('warn')
            ->and($down->severity->value)->toBe('medium')
            ->and(postureResult(new LaravelMaintenanceModeCheck)->status->value)->toBe('pass');
    });
});

it('reports posture through excubia:check and drops ignored rows', function () {
    postureApp([
        'excubia.json' => '{"ignore": [{"checkId": "laravel-maintenance-mode", "subject": "maintenance-mode", "reason": "Planned"}]}',
    ]);
    app()['env'] = 'production';
    config(['app.debug' => true]);
    Artisan::call('down');

    Artisan::call('excubia:check', ['--format' => 'json', '--no-banner' => true, '--fail-on' => 'high']);
    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    $statuses = array_column($report['checks'], 'status', 'checkId');
    Artisan::call('up');

    expect($statuses['laravel-debug-mode'])->toBe('fail')
        ->and($statuses['laravel-maintenance-mode'])->toBe('pass')
        ->and($statuses['laravel-scheduler-heartbeat'])->toBe('warn');
});

it('keeps .env values, DSNs, hostnames and paths out of every posture finding', function () {
    $project = postureApp([
        'database/migrations/2026_01_01_000000_create_widgets_table.php' => migrationFile('widgets'),
    ]);
    putenv('EXCUBIA_PRIVACY_SENTINEL=env-value-sentinel');
    app()['env'] = 'production';
    config([
        'app.debug' => true,
        'app.key' => 'base64:app-key-sentinel',
        'app.url' => 'https://app-host-sentinel.example',
        'database.connections.mysql.host' => 'db-host-sentinel.internal',
        'database.connections.mysql.password' => 'db-password-sentinel',
    ]);
    createFailedJobsTable();
    insertFailedJob(date('Y-m-d H:i:s', time() - 48 * 3600));
    Artisan::call('down');

    Artisan::call('excubia:check', ['--format' => 'json', '--no-banner' => true, '--fail-on' => 'none']);
    $output = Artisan::output();
    Artisan::call('up');
    putenv('EXCUBIA_PRIVACY_SENTINEL');
    // The broken connection makes the migration status unreadable; its error names the path.
    config(['database.connections.testing.database' => $project.'/missing.sqlite']);
    DB::purge('testing');
    Artisan::call('excubia:check', ['--format' => 'json', '--no-banner' => true, '--fail-on' => 'none', '--only' => 'laravel-pending-migrations,laravel-failed-jobs']);
    $output .= Artisan::output();

    expect($output)->toContain('Debug mode is on in the production environment.', '1 failed job.', 'The migration status could not be read')
        ->not->toContain('sentinel')
        ->not->toContain($project)
        ->not->toContain(sys_get_temp_dir())
        ->not->toContain(base_path());
});
