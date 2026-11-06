<?php

declare(strict_types=1);

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Qiick\Excubia\Console\PushCommand;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\LaravelPostureService;
use Qiick\Excubia\Laravel\ScheduledTaskIdentityService;
use Qiick\Excubia\Services\RuntimeBufferService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * An app with an empty lockfile, runtime monitoring on and a schedule that
 * covers every inventory field (#146). Returns the base path.
 */
function scheduleInventoryApp(Application $app): string
{
    $project = temporaryProject([
        'composer.lock' => '{"packages": [], "packages-dev": []}',
        'excubia.json' => json_encode(['runtime' => ['enabled' => true], 'cache' => ['directory' => temporaryProject([])]], JSON_THROW_ON_ERROR),
    ]);
    $app->setBasePath($project);
    $app->useStoragePath(temporaryProject([]));
    $app->register(ExcubiaServiceProvider::class, true);
    $schedule = $app->make(Schedule::class);
    $schedule->command('inspire --quote=inventory-argument-secret')->hourly()->withoutOverlapping()->onOneServer()->timezone('Europe/Rome');
    $schedule->exec('/usr/bin/mysqldump -pinventory-password-secret shop > '.$project.'/storage/dump.sql')->dailyAt('02:00')->environments(['production', 'staging']);
    $schedule->call(fn () => null)->everyFiveMinutes();
    $schedule->call(fn () => null)->description('nightly cleanup in '.$project.'/storage token=inventory-token-secret')->daily()->evenInMaintenanceMode();

    return $project;
}

/**
 * The `excubia:check --format=json` report of the booted app.
 *
 * @return array<string, mixed>
 */
function scheduleInventoryReport(): array
{
    Artisan::call('excubia:check', ['--only' => 'laravel-app-key', '--offline' => true, '--fail-on' => 'none', '--no-banner' => true, '--format' => 'json']);

    return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $report
 * @return array<string, array<string, mixed>>
 */
function scheduleByTask(array $report): array
{
    return collect($report['schedule'])->keyBy('task')->all();
}

it('adds the schedule inventory to the excubia:check report with the runtime naming, the timezone and the flags', function () {
    scheduleInventoryApp($this->app);

    $schedule = scheduleByTask(scheduleInventoryReport());

    expect(array_keys($schedule))->toEqualCanonicalizing(['artisan excubia:heartbeat', 'artisan excubia:push-runtime', 'artisan inspire', 'mysqldump', 'Closure', 'nightly cleanup in storage token=[masked]'])
        ->and($schedule['artisan inspire'])->toBe([
            'taskId' => hash('sha256', "artisan inspire\0".'0 * * * *'),
            'task' => 'artisan inspire',
            'expression' => '0 * * * *',
            'timezone' => 'Europe/Rome',
            'withoutOverlapping' => true,
            'onOneServer' => true,
            'runsInMaintenanceMode' => false,
            'environments' => [],
            'lateAfterMinutes' => 5,
        ])
        ->and($schedule['mysqldump'])->toMatchArray(['expression' => '0 2 * * *', 'timezone' => 'UTC', 'withoutOverlapping' => false, 'onOneServer' => false, 'environments' => ['production', 'staging']])
        ->and($schedule['Closure'])->toMatchArray(['expression' => '*/5 * * * *', 'runsInMaintenanceMode' => false])
        ->and($schedule['nightly cleanup in storage token=[masked]'])->toMatchArray(['expression' => '0 0 * * *', 'runsInMaintenanceMode' => true])
        ->and($schedule['artisan excubia:heartbeat']['expression'])->toBe('* * * * *');
});

it('puts the configured late-run grace on every schedule entry', function () {
    $project = scheduleInventoryApp($this->app);
    file_put_contents($project.'/excubia.json', json_encode(['checks' => ['laravel-scheduler-heartbeat' => ['lateAfterMinutes' => 15]], 'cache' => ['directory' => temporaryProject([])]], JSON_THROW_ON_ERROR));

    expect(array_unique(array_column(scheduleInventoryReport()['schedule'], 'lateAfterMinutes')))->toBe([15]);
});

it('emits a schedule that matches docs/report-schema.json', function () {
    scheduleInventoryApp($this->app);
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/docs/report-schema.json'), false, 512, JSON_THROW_ON_ERROR);
    $schema->{'$schema'} = 'http://json-schema.org/draft-07/schema#';
    $validator = new Validator;
    $document = json_decode(json_encode(scheduleInventoryReport(), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    $validator->validate($document, $schema, Constraint::CHECK_MODE_STRICT);

    expect($validator->getErrors())->toBe([]);
});

it('never puts arguments, secrets, absolute paths or the PHP binary in the schedule', function () {
    $project = scheduleInventoryApp($this->app);

    Artisan::call('excubia:check', ['--only' => 'laravel-app-key', '--offline' => true, '--fail-on' => 'none', '--no-banner' => true, '--format' => 'json']);
    $raw = Artisan::output();

    foreach (['inventory-argument-secret', 'inventory-password-secret', 'inventory-token-secret', 'shop', 'dump.sql', '--quote', $project, PHP_BINARY, '/usr/bin'] as $forbidden) {
        expect($raw)->not->toContain($forbidden);
    }
});

it('gives the runtime scheduledTask aggregates the taskId of their schedule entry', function () {
    scheduleInventoryApp($this->app);
    $inventory = scheduleByTask(scheduleInventoryReport());

    foreach ($this->app->make(Schedule::class)->events() as $event) {
        event(new ScheduledTaskFinished($event, 0.1));
    }

    $buffer = (string) file_get_contents($this->app->storagePath('excubia/runtime/'.RuntimeBufferService::BUFFER_FILE));
    $aggregates = array_map(fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", $buffer))));
    $runs = collect(RuntimeBufferService::merge($aggregates))->where('type', 'scheduledTask')->keyBy('task');

    expect($runs->keys()->all())->toEqualCanonicalizing(array_keys($inventory));

    foreach ($inventory as $task => $entry) {
        expect($runs[$task]['taskId'])->toBe($entry['taskId'])
            ->and($runs[$task]['histogram'])->toHaveCount(12);
    }

    $validator = new Validator;
    $payload = json_decode(json_encode(['bucketSeconds' => 60, 'aggregates' => $runs->values()->all()], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $validator->validate($payload, (object) ['$ref' => 'file://'.dirname(__DIR__, 2).'/docs/runtime-schema.json']);

    expect($validator->getErrors())->toBe([])
        ->and($buffer)->not->toContain('inventory-argument-secret')
        ->and($buffer)->not->toContain('inventory-token-secret');
});

it('sends the schedule with excubia:push', function () {
    scheduleInventoryApp($this->app);
    $client = new FakeHttpClient(['https://excubia.example.test/api/reports' => ['stored' => true]]);
    $posture = (new LaravelPostureService)->collect($this->app);
    $tester = new CommandTester(new PushCommand($client, new DateTimeImmutable('2026-11-06 08:00:00', new DateTimeZone('UTC')), ['EXCUBIA_APP_URL' => 'https://excubia.example.test', 'EXCUBIA_APP_TOKEN' => 'token'], laravelPosture: $posture));

    expect($tester->execute(['path' => $this->app->basePath(), '--config' => $this->app->basePath('excubia.json')]))->toBe(0)
        ->and($client->postedPayloads[0]['report']['schedule'])->toBe($posture->scheduledTasks)
        ->and(array_column($posture->scheduledTasks ?? [], 'task'))->toContain('artisan inspire');
});

it('leaves the schedule out when it could not be read', function () {
    $this->app->setBasePath(temporaryProject(['composer.lock' => '{"packages": [], "packages-dev": []}']));
    $this->app->bind(Schedule::class, fn () => throw new RuntimeException('no schedule'));

    expect((new LaravelPostureService)->collect($this->app)->scheduledTasks)->toBeNull()
        ->and(scheduleInventoryReport())->not->toHaveKey('schedule');
});

it('names a task the same way in the inventory and in the runtime aggregates', function () {
    $event = $this->app->make(Schedule::class)->command('backup:run --password=x');

    expect(ScheduledTaskIdentityService::name($event, '/srv/app'))->toBe('artisan backup:run')
        ->and(ScheduledTaskIdentityService::id('artisan backup:run', '* * * * *'))->toBe(hash('sha256', "artisan backup:run\0* * * * *"))
        ->and(ScheduledTaskIdentityService::id('artisan backup:run', '* * * * *'))->toMatch('/^[0-9a-f]{64}$/');
});
