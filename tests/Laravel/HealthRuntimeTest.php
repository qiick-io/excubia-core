<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use JsonSchema\Validator;
use Qiick\Excubia\Contracts\DiskSpaceContract;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Data\RuntimeConfigurationData;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\LaravelPostureService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Qiick\Excubia\Tests\Support\FakeServerDisk;

const HEALTH_RUNTIME_ENDPOINT = 'https://excubia.example.test/api/runtime';

/**
 * A project with runtime monitoring and `health` on, its own storage and a
 * fake HTTP client bound for `excubia:push-runtime`.
 *
 * @param  array<string, mixed>  $health  merged into runtime.health
 * @param  array<string, mixed>  $document  merged into excubia.json
 */
function healthRuntimeProject(Application $app, FakeHttpClient $client, array $health = [], array $document = []): void
{
    $document['runtime'] = ['enabled' => true, 'server' => ['name' => 'web-1'], 'health' => ['enabled' => true, ...$health]];
    $app->setBasePath(temporaryProject(['excubia.json' => json_encode($document, JSON_THROW_ON_ERROR)]));
    $app->useStoragePath(temporaryProject([]));
    $app->register(ExcubiaServiceProvider::class, true);
    $app->instance(HttpClientContract::class, $client);
}

/**
 * The `health` aggregates of every runtime payload the fake client posted.
 *
 * @return list<array<string, mixed>>
 */
function postedHealthAggregates(FakeHttpClient $client): array
{
    $aggregates = [];

    foreach ($client->postedPayloads as $payload) {
        foreach ($payload['runtime']['aggregates'] ?? [] as $aggregate) {
            if ($aggregate['type'] === 'health') {
                $aggregates[] = $aggregate;
            }
        }
    }

    return $aggregates;
}

/**
 * @param  array<string, mixed>  $runtime
 * @return list<string>
 */
function healthRuntimeSchemaErrors(array $runtime): array
{
    $validator = new Validator;
    $document = json_decode(json_encode($runtime, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $validator->validate($document, (object) ['$ref' => 'file://'.dirname(__DIR__, 2).'/docs/runtime-schema.json']);

    return array_map(fn (array $error): string => $error['property'].': '.$error['message'], $validator->getErrors());
}

beforeEach(function () {
    putenv('EXCUBIA_APP_URL=https://excubia.example.test/');
    putenv('EXCUBIA_APP_TOKEN=runtime-token-secret');
});

afterEach(function () {
    putenv('EXCUBIA_APP_URL');
    putenv('EXCUBIA_APP_TOKEN');
});

it('sends one health aggregate per default check, valid against the schema, without calling a data source', function () {
    $client = new FakeHttpClient([HEALTH_RUNTIME_ENDPOINT => ['stored' => true]]);
    healthRuntimeProject($this->app, $client);

    $this->artisan('excubia:push-runtime')->expectsOutputToContain('Sent 1 runtime batch to excubia-app.')->assertExitCode(0);

    $aggregates = postedHealthAggregates($client);
    $byCheck = collect($aggregates)->keyBy('checkId');

    expect(array_column($aggregates, 'checkId'))->toBe(RuntimeConfigurationData::HEALTH_CHECK_IDS)
        ->and($client->requests)->toHaveCount(1)
        ->and($client->requests[0])->toStartWith('POST '.HEALTH_RUNTIME_ENDPOINT.' ')
        ->and($byCheck['laravel-database'])->toMatchArray(['server' => 'web-1', 'count' => 1, 'errorCount' => 0, 'status' => 'pass', 'severity' => 'info', 'findingCount' => 0, 'fingerprints' => [], 'sampleRate' => 1])
        ->and($byCheck['laravel-cache']['status'])->toBe('pass')
        ->and($byCheck['laravel-maintenance-mode']['status'])->toBe('pass')
        ->and(healthRuntimeSchemaErrors($client->postedPayloads[0]['runtime']))->toBe([]);
});

it('sends the configured checks only, with the fingerprints of failing findings and no names or messages', function () {
    $client = new FakeHttpClient([HEALTH_RUNTIME_ENDPOINT => ['stored' => true]]);
    healthRuntimeProject($this->app, $client, ['checks' => ['laravel-maintenance-mode', 'laravel-database']], ['checks' => ['laravel-database' => ['connections' => ['orders-replica-secret']]]]);

    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    $aggregates = postedHealthAggregates($client);
    $encoded = json_encode($client->postedPayloads, JSON_THROW_ON_ERROR);

    expect(array_column($aggregates, 'checkId'))->toBe(['laravel-maintenance-mode', 'laravel-database'])
        ->and($aggregates[1])->toMatchArray([
            'status' => 'fail',
            'severity' => 'critical',
            'errorCount' => 1,
            'findingCount' => 1,
            'fingerprints' => [hash('sha256', "laravel-database\0orders-replica-secret\0")],
        ])
        ->and($encoded)->not->toContain('orders-replica-secret')
        ->and($encoded)->not->toContain('did not answer')
        ->and($encoded)->not->toContain($this->app->storagePath())
        ->and($encoded)->not->toContain((string) gethostname())
        ->and(healthRuntimeSchemaErrors($client->postedPayloads[0]['runtime']))->toBe([]);
});

it('sends no health aggregate when the type is off or not sampled', function (array $runtime) {
    $client = new FakeHttpClient([HEALTH_RUNTIME_ENDPOINT => ['stored' => true]]);
    healthRuntimeProject($this->app, $client);
    $this->app->setBasePath(temporaryProject(['excubia.json' => json_encode(['runtime' => $runtime], JSON_THROW_ON_ERROR)]));

    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    expect(postedHealthAggregates($client))->toBe([])
        ->and($client->requests)->toBe([]);
})->with([
    'off by default' => [['enabled' => true]],
    'switched off' => [['enabled' => true, 'health' => ['enabled' => false]]],
    'sample rate 0' => [['enabled' => true, 'health' => ['enabled' => true, 'sampleRate' => 0]]],
]);

it('samples at most once per minute', function () {
    $client = new FakeHttpClient([HEALTH_RUNTIME_ENDPOINT => ['stored' => true]]);
    healthRuntimeProject($this->app, $client, ['checks' => ['laravel-maintenance-mode']]);
    $minutePath = $this->app->storagePath('excubia/runtime/health-minute');

    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    $sampled = postedHealthAggregates($client);

    expect($sampled)->toHaveCount(1)
        ->and(file_get_contents($minutePath).':00Z')->toBe($sampled[0]['bucket']);

    // Retried once: a run that straddles a minute boundary samples again, by design.
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $client->postedPayloads = [];
        $minute = gmdate('Y-m-d\TH:i');
        file_put_contents($minutePath, $minute);
        $this->artisan('excubia:push-runtime')->assertExitCode(0);

        if (gmdate('Y-m-d\TH:i') === $minute) {
            break;
        }
    }

    expect(postedHealthAggregates($client))->toBe([]);

    file_put_contents($minutePath, '2026-01-01T00:00');
    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    expect(postedHealthAggregates($client))->toHaveCount(1);
});

it('sends a check whose probe throws as skipped and still runs the next checks and the push', function () {
    $client = new FakeHttpClient([HEALTH_RUNTIME_ENDPOINT => ['stored' => true]]);
    healthRuntimeProject($this->app, $client, ['checks' => ['laravel-disk-space', 'laravel-maintenance-mode']]);
    $this->app->instance(DiskSpaceContract::class, new class implements DiskSpaceContract
    {
        public function usedPercent(string $directory): ?int
        {
            throw new RuntimeException('disk probe exploded at /secret/path');
        }

        public function usage(string $directory): ?array
        {
            throw new RuntimeException('disk probe exploded at /secret/path');
        }
    });

    $this->artisan('excubia:push-runtime')->expectsOutputToContain('Sent 1 runtime batch to excubia-app.')->assertExitCode(0);

    $aggregates = postedHealthAggregates($client);

    expect(array_column($aggregates, 'status', 'checkId'))->toBe(['laravel-disk-space' => 'skipped', 'laravel-maintenance-mode' => 'pass'])
        ->and(json_encode($client->postedPayloads, JSON_THROW_ON_ERROR))->not->toContain('/secret/path');
});

it('starts no check without a whole check budget left of the total budget', function () {
    $client = new FakeHttpClient([HEALTH_RUNTIME_ENDPOINT => ['stored' => true]]);
    healthRuntimeProject($this->app, $client, ['checks' => ['laravel-database', 'laravel-cache'], 'checkBudgetMs' => 1000, 'totalBudgetMs' => 1000]);

    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    expect(array_column(postedHealthAggregates($client), 'status', 'checkId'))->toBe(['laravel-database' => 'skipped', 'laravel-cache' => 'skipped']);
});

it('probes only what the given checks read', function () {
    $service = new LaravelPostureService(new FakeServerDisk);

    $maintenance = $service->collect($this->app, checkIds: ['laravel-maintenance-mode']);
    $diskOnly = $service->collect($this->app, checkIds: ['laravel-disk-space']);
    $full = $service->collect($this->app);

    expect($maintenance->databaseConnectionReachability)->toBe([])
        ->and($maintenance->isCacheStoreWorking)->toBeNull()
        ->and($maintenance->diskUsedPercent)->toBeNull()
        ->and($maintenance->scheduledTasks)->toBeNull()
        ->and($maintenance->pendingMigrations)->toBeNull()
        ->and($maintenance->isMaintenanceModeActive)->toBeFalse()
        ->and($diskOnly->diskUsedPercent)->toBe(37)
        ->and($diskOnly->databaseConnectionReachability)->toBe([])
        ->and($full->databaseConnectionReachability)->not->toBe([])
        ->and($full->isCacheStoreWorking)->toBeTrue()
        ->and($full->scheduledTasks)->not->toBeNull();
});
