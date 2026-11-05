<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use JsonSchema\Validator;
use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Tests\Support\FakeHttpClient;

const RUNTIME_ENDPOINT = 'https://excubia.example.test/api/runtime';
const RUNTIME_TOKEN = 'runtime-token-secret';

/**
 * A project with runtime monitoring on, its own storage, a fake HTTP client
 * bound for `excubia:push-runtime`, and one recorded request in the buffer.
 *
 * @param  array<string, mixed>  $document  merged into excubia.json
 */
function runtimePushProject(Application $app, FakeHttpClient $client, array $document = ['runtime' => ['enabled' => true]]): void
{
    $app->setBasePath(temporaryProject(['excubia.json' => json_encode($document, JSON_THROW_ON_ERROR)]));
    $app->useStoragePath(temporaryProject([]));
    $app->register(ExcubiaServiceProvider::class, true);
    $app->instance(HttpClientContract::class, $client);
}

/**
 * @return list<string>
 */
function runtimeBatchFiles(Application $app): array
{
    return array_map('basename', glob($app->storagePath('excubia/runtime/batch-*.ndjson')) ?: []);
}

/**
 * Validate one runtime payload against docs/runtime-schema.json.
 *
 * @param  array<string, mixed>  $runtime
 * @return list<string>
 */
function runtimeSchemaErrors(array $runtime): array
{
    $validator = new Validator;
    $document = json_decode(json_encode($runtime, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $validator->validate($document, (object) ['$ref' => 'file://'.dirname(__DIR__, 2).'/docs/runtime-schema.json']);

    return array_map(fn (array $error): string => $error['property'].': '.$error['message'], $validator->getErrors());
}

function recordOneRequest(mixed $test): void
{
    Route::get('/dashboard', fn () => 'ok')->name('dashboard');
    $test->get('/dashboard')->assertOk();
}

beforeEach(function () {
    putenv('EXCUBIA_APP_URL=https://excubia.example.test/');
    putenv('EXCUBIA_APP_TOKEN='.RUNTIME_TOKEN);
});

afterEach(function () {
    putenv('EXCUBIA_APP_URL');
    putenv('EXCUBIA_APP_TOKEN');
});

it('sends a valid runtime batch with the bearer token and deletes it after 2xx', function () {
    $client = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true]]);
    runtimePushProject($this->app, $client);
    recordOneRequest($this);

    $this->artisan('excubia:push-runtime')
        ->expectsOutputToContain('Sent 1 runtime batch to excubia-app.')
        ->assertExitCode(0);

    $payload = $client->postedPayloads[0];

    expect($client->lastPostUrl)->toBe(RUNTIME_ENDPOINT)
        ->and($client->lastHeaders)->toBe(['Authorization' => 'Bearer '.RUNTIME_TOKEN])
        ->and(array_keys($payload))->toBe(['protocolVersion', 'type', 'batchId', 'sentAt', 'runtime'])
        ->and($payload['protocolVersion'])->toBe(1)
        ->and($payload['type'])->toBe('runtime')
        ->and($payload['batchId'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($payload['sentAt'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($payload['runtime']['bucketSeconds'])->toBe(60)
        ->and($payload['runtime']['aggregates'][0])->toMatchArray(['type' => 'request', 'route' => 'dashboard', 'count' => 1])
        ->and(runtimeSchemaErrors($payload['runtime']))->toBe([])
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain(RUNTIME_TOKEN)
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain($this->app->basePath())
        ->and(runtimeBatchFiles($this->app))->toBe([]);
});

it('keeps the batch on 5xx or an unreachable app and resends it with the same batchId', function (DataSourceUnavailableException $failure, string $message) {
    $failing = new FakeHttpClient([], [RUNTIME_ENDPOINT => $failure]);
    runtimePushProject($this->app, $failing);
    recordOneRequest($this);

    $this->artisan('excubia:push-runtime')
        ->expectsOutputToContain($message)
        ->expectsOutputToContain('The runtime data is kept for the next run.')
        ->assertExitCode(3);

    expect(runtimeBatchFiles($this->app))->toHaveCount(1);

    $working = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true]]);
    $this->app->instance(HttpClientContract::class, $working);

    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    expect($working->postedPayloads)->toHaveCount(1)
        ->and($working->postedPayloads[0]['batchId'])->toBe($failing->postedPayloads[0]['batchId'])
        ->and(runtimeBatchFiles($this->app))->toBe([]);
})->with([
    'HTTP 503' => [new DataSourceUnavailableException('http', 'The data source returned HTTP 503.', httpStatus: 503), 'did not accept the runtime batch (HTTP 503)'],
    'unreachable' => [new DataSourceUnavailableException('http', 'The data source did not respond.'), 'could not be reached'],
    'token rejected' => [new DataSourceUnavailableException('http', 'The data source returned HTTP 401.', httpStatus: 401), 'rejected the project token'],
]);

it('sends older kept batches first, each with its own batchId', function () {
    $failing = new FakeHttpClient([], [RUNTIME_ENDPOINT => new DataSourceUnavailableException('http', 'down')]);
    runtimePushProject($this->app, $failing);
    recordOneRequest($this);
    $this->artisan('excubia:push-runtime')->assertExitCode(3);
    $this->get('/dashboard')->assertOk();

    $working = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true]]);
    $this->app->instance(HttpClientContract::class, $working);

    $this->artisan('excubia:push-runtime')
        ->expectsOutputToContain('Sent 2 runtime batches to excubia-app.')
        ->assertExitCode(0);

    expect($working->postedPayloads[0]['batchId'])->toBe($failing->postedPayloads[0]['batchId'])
        ->and($working->postedPayloads[1]['batchId'])->not->toBe($failing->postedPayloads[0]['batchId']);
});

it('reports that there is nothing to send', function () {
    $client = new FakeHttpClient([]);
    runtimePushProject($this->app, $client);

    $this->artisan('excubia:push-runtime')
        ->expectsOutputToContain('No runtime data to send.')
        ->assertExitCode(0);

    expect($client->calls)->toBe(0);
});

it('sends nothing and exits 0 when runtime monitoring is disabled', function () {
    $client = new FakeHttpClient([]);
    runtimePushProject($this->app, $client, []);

    $this->artisan('excubia:push-runtime')
        ->expectsOutputToContain('Runtime monitoring is disabled')
        ->assertExitCode(0);

    expect($client->calls)->toBe(0);
});

it('exits 2 and keeps the data when no excubia-app is configured', function () {
    putenv('EXCUBIA_APP_URL');
    $client = new FakeHttpClient([]);
    runtimePushProject($this->app, $client);
    recordOneRequest($this);

    $this->artisan('excubia:push-runtime')
        ->expectsOutputToContain('No excubia-app URL')
        ->assertExitCode(2);

    expect($client->calls)->toBe(0)
        ->and(runtimeBatchFiles($this->app))->toHaveCount(1);
});

it('reads the app keys from excubia.json when the environment has none', function () {
    putenv('EXCUBIA_APP_URL');
    putenv('EXCUBIA_APP_TOKEN');
    $client = new FakeHttpClient(['https://file.example.test/api/runtime' => ['stored' => true]]);
    runtimePushProject($this->app, $client, ['app' => ['url' => 'https://file.example.test', 'token' => 'file-token'], 'runtime' => ['enabled' => true]]);
    recordOneRequest($this);

    $this->artisan('excubia:push-runtime')
        ->expectsOutputToContain(CheckCommand::APP_TOKEN_IN_FILE_WARNING)
        ->assertExitCode(0);

    expect($client->lastHeaders)->toBe(['Authorization' => 'Bearer file-token']);
});

it('exits 2 on an invalid excubia.json', function () {
    $this->app->setBasePath(temporaryProject(['excubia.json' => '{"runtime": {"enabled": "yes"}}']));

    $this->artisan('excubia:push-runtime')
        ->expectsOutputToContain('runtime.enabled must be a boolean.')
        ->assertExitCode(2);
});

it('registers excubia:push-runtime', function () {
    expect(array_keys(Artisan::all()))->toContain('excubia:push-runtime');
});
