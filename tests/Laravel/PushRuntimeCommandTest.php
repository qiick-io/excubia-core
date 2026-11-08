<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use JsonSchema\Validator;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Tools\ToolNameResolver;
use Laravel\Cashier\Events\WebhookReceived as StripeWebhookReceived;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Reverb\Application as ReverbApplication;
use Laravel\Reverb\Contracts\Connection as ReverbConnection;
use Laravel\Reverb\Events\MessageReceived as ReverbMessageReceived;
use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Contracts\DiskSpaceContract;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Contracts\ServerResourcesContract;
use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Tests\Support\FakeAiAgent;
use Qiick\Excubia\Tests\Support\FakeAiTool;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Qiick\Excubia\Tests\Support\FakeServerDisk;
use Qiick\Excubia\Tests\Support\FakeServerResources;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Mailer\Exception\TransportException;

require_once dirname(__DIR__).'/Support/FakeOctaneEvents.php';
require_once dirname(__DIR__).'/Support/FakeReverbEvents.php';
require_once dirname(__DIR__).'/Support/FakeCashierClasses.php';

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
    putenv('EXCUBIA_SERVER_NAME');
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

it('adds the release to the runtime batch, from excubia.json or the environment, and omits an invalid one', function (array $document, ?string $environment, ?string $expected) {
    $client = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true]]);
    runtimePushProject($this->app, $client, ['runtime' => ['enabled' => true], ...$document]);
    recordOneRequest($this);
    putenv($environment === null ? 'EXCUBIA_RELEASE' : 'EXCUBIA_RELEASE='.$environment);

    try {
        $this->artisan('excubia:push-runtime')->assertExitCode(0);
    } finally {
        putenv('EXCUBIA_RELEASE');
    }

    $runtime = $client->postedPayloads[0]['runtime'];

    expect($runtime['release'] ?? null)->toBe($expected)
        ->and(array_keys($runtime))->toBe($expected === null ? ['bucketSeconds', 'environment', 'aggregates'] : ['bucketSeconds', 'release', 'environment', 'aggregates'])
        ->and(runtimeSchemaErrors($runtime))->toBe([]);
})->with([
    'environment' => [[], 'v2.3.4', 'v2.3.4'],
    'config wins' => [['release' => 'v9.0.0'], 'v2.3.4', 'v9.0.0'],
    'invalid' => [[], 'not valid!', null],
    'none' => [[], null, null],
]);

it('adds the Laravel environment to the runtime batch and omits one that is not a short name', function (string $environment, ?string $expected) {
    $client = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true]]);
    runtimePushProject($this->app, $client);
    recordOneRequest($this);
    $this->app['env'] = $environment;

    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    $runtime = $client->postedPayloads[0]['runtime'];

    expect($runtime['environment'] ?? null)->toBe($expected)
        ->and(runtimeSchemaErrors($runtime))->toBe([]);
})->with([
    'production' => ['production', 'production'],
    'staging' => ['staging', 'staging'],
    'hostname' => ['app.example.com', null],
    'too long' => [str_repeat('a', 65), null],
    'spaces' => ['my env', null],
]);

it('does not read .git for runtime batches', function () {
    $client = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true]]);
    runtimePushProject($this->app, $client);
    mkdir($this->app->basePath('.git'));
    file_put_contents($this->app->basePath('.git/HEAD'), "0123456789abcdef0123456789abcdef01234567\n");
    recordOneRequest($this);

    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    expect($client->postedPayloads[0]['runtime'])->not->toHaveKey('release');
});

it('sends every aggregate type, the 1.0.0-beta.2 ones included, as a valid runtime batch', function () {
    $client = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true], 'https://excubia.example.test/api/exceptions' => ['stored' => true]]);
    $runtime = ['enabled' => true, 'slowQueries' => ['thresholdMs' => 0]];

    foreach (RuntimeTypeEnum::cases() as $type) {
        $runtime[$type->configurationKey()] ??= ['enabled' => true];
        $runtime[$type->configurationKey()]['enabled'] = true;
    }

    $this->app->register(AiServiceProvider::class);
    runtimePushProject($this->app, $client, ['runtime' => $runtime, 'exceptions' => ['enabled' => true]]);
    config(['queue.default' => 'sync', 'mail.default' => 'array']);
    FakeAiAgent::fake([new ToolCall('call-1', ToolNameResolver::resolve(new FakeAiTool), ['query' => 'order 1']), 'done']);
    Http::fake(['api.example.test/*' => Http::response(['ok' => true])]);
    recordOneRequest($this);
    DB::select('select 1');
    dispatch(static function (): void {
        for ($i = 0; $i < 5; $i++) {
            DB::select('select ? as repeated_column', [$i]);
        }
    });
    event(new ScheduledTaskFinished($this->app->make(Schedule::class)->command('inspire'), 0.1));
    Http::get('https://api.example.test/ping');
    Cache::put('settings:site', 'value');
    Mail::raw('body', fn ($message) => $message->to('someone@example.test'));
    report(new TransportException('smtp failure'));
    event(new NotificationSent(new AnonymousNotifiable, new class extends Notification {}, 'mail'));
    Log::warning('something');
    trigger_error('Old::api() is deprecated', E_USER_DEPRECATED);
    event(new CommandStarting('migrate', new ArrayInput([]), new NullOutput));
    event(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 1));
    (new FakeAiAgent)->prompt('hello');
    event(new WorkerStarting($this->app));
    event(new ReverbMessageReceived(new ReverbConnection(new ReverbApplication('123456')), '{}'));
    event(new StripeWebhookReceived(['type' => 'invoice.payment_failed']));
    $this->app->terminate();

    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    $runtimePayload = collect($client->postedPayloads)->firstWhere('type', 'runtime')['runtime'];
    $types = collect($runtimePayload['aggregates'])->pluck('type')->unique()->sort()->values()->all();
    $expected = collect(RuntimeTypeEnum::cases())->map(fn (RuntimeTypeEnum $type): string => $type->value)->sort()->values()->all();

    expect($types)->toBe($expected)
        ->and(runtimeSchemaErrors($runtimePayload))->toBe([])
        ->and(collect($runtimePayload['aggregates'])->firstWhere('type', 'notification')['notification'])->toBe(Notification::class.'@anonymous')
        ->and(json_encode($runtimePayload, JSON_THROW_ON_ERROR))->not->toContain('someone@example.test');

    foreach ($runtimePayload['aggregates'] as $aggregate) {
        expect(array_key_exists('histogram', $aggregate))->toBe(array_key_exists('maxMs', $aggregate));

        if (isset($aggregate['histogram'])) {
            expect(array_sum($aggregate['histogram']))->toBe($aggregate['count']);
        }
    }
});

it('never lets an AI prompt, attachment, response, reasoning, tool argument or result, conversation or exception message reach the buffer or the push', function () {
    $marker = 'excubia-ai-privacy-marker-91c2';
    $client = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true]]);
    $this->app->register(AiServiceProvider::class);
    runtimePushProject($this->app, $client, ['runtime' => ['enabled' => true, 'ai' => ['enabled' => true], 'aiTools' => ['enabled' => true]]]);
    $toolName = ToolNameResolver::resolve(new FakeAiTool);
    FakeAiAgent::fake([
        new ToolCall('call-'.$marker, $toolName, ['query' => $marker]),
        (new AgentResponse('invocation-'.$marker, 'answer '.$marker, new TextUsage(30, 12, null, null, 4), new Meta('openai', 'gpt-test')))->withReasoning('thinking about '.$marker),
    ]);
    $agent = new FakeAiAgent('Instructions with '.$marker);

    $agent->prompt('Prompt with '.$marker, [Document::fromString('Attachment with '.$marker, 'text/plain')], model: 'gpt-test');
    FakeAiAgent::fake([new ToolCall('call-2', $toolName, ['query' => $marker, 'fail' => true]), 'tool failed']);

    expect(fn () => $agent->prompt('Tool failing with '.$marker, model: 'gpt-test'))->toThrow(RuntimeException::class);

    FakeAiAgent::fake([new TextResponse('streamed '.$marker, new TextUsage(5, 5), new Meta('openai', 'gpt-test'))]);

    foreach ($agent->stream('Stream with '.$marker, model: 'gpt-test') as $streamEvent) {
        // Read the whole stream.
    }

    FakeAiAgent::fake(fn () => throw new RuntimeException('failed for '.$marker));

    expect(fn () => $agent->prompt('Failing with '.$marker, model: 'gpt-test'))->toThrow(RuntimeException::class);

    Image::fake(fn () => throw new RateLimitedException('image failed for '.$marker));

    expect(fn () => Image::of('Image of '.$marker)->generate(['gemini' => 'image-a', 'openai' => 'image-b']))->toThrow(RateLimitedException::class);

    $this->app->terminate();
    $buffer = (string) file_get_contents($this->app->storagePath('excubia/runtime/buffer.ndjson'));

    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    $runtimePayload = collect($client->postedPayloads)->firstWhere('type', 'runtime')['runtime'];
    $aggregates = collect($runtimePayload['aggregates']);

    expect($buffer)->toContain('"type":"ai"')
        ->and($buffer)->not->toContain($marker)
        ->and(json_encode($client->postedPayloads, JSON_THROW_ON_ERROR))->not->toContain($marker)
        ->and(runtimeSchemaErrors($runtimePayload))->toBe([])
        ->and($aggregates->where('type', 'ai')->where('operation', 'agent')->sum('count'))->toBe(4)
        ->and($aggregates->where('type', 'ai')->where('operation', 'agent')->sum('inputTokens'))->toBe(35)
        ->and($aggregates->where('type', 'ai')->where('operation', 'image')->sum('failoverCount'))->toBe(2)
        ->and($aggregates->where('type', 'aiTool')->sum('count'))->toBe(2)
        ->and($aggregates->where('type', 'aiTool')->sum('errorCount'))->toBe(1);
});

it('never lets a classified text, its data, the questions, attachments, answers or exception message reach the buffer or the push', function () {
    $marker = 'excubia-ai-classification-marker-5d0e';
    $client = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true]]);
    $this->app->register(AiServiceProvider::class);
    runtimePushProject($this->app, $client, ['runtime' => ['enabled' => true, 'ai' => ['enabled' => true]]]);
    $questions = [
        'topic-'.$marker => new Choice('Which topic is '.$marker.' about?', ['billing-'.$marker => 'Billing '.$marker, 'other' => 'Other']),
        'urgent' => new Boolean(['Is it urgent?', 'Answer about '.$marker]),
    ];
    Classification::fake([
        new ClassificationResponse([
            'topic-'.$marker => new ChoiceAnswer('billing-'.$marker, ['billing-'.$marker => 0.8, 'other' => 0.2], 0.8),
            'urgent' => new BooleanAnswer(0.7),
        ], new TextUsage(80, 6), new Meta('openai', 'gpt-classify')),
        fn () => throw new RuntimeException('classification failed for '.$marker),
    ]);

    Classification::of('Customer message about '.$marker, [Document::fromString('Attachment with '.$marker, 'text/plain')])
        ->questions($questions)
        ->classify('openai', 'gpt-classify');

    expect(fn () => Classification::of(['ticket' => $marker, 'body' => 'Structured '.$marker])->questions($questions)->classify('openai', 'gpt-classify'))
        ->toThrow(RuntimeException::class);

    $this->app->terminate();
    $buffer = (string) file_get_contents($this->app->storagePath('excubia/runtime/buffer.ndjson'));

    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    $runtimePayload = collect($client->postedPayloads)->firstWhere('type', 'runtime')['runtime'];
    $classification = collect($runtimePayload['aggregates'])->where('type', 'ai')->where('operation', 'classification');

    expect($buffer)->toContain('"operation":"classification"')
        ->and($buffer)->not->toContain($marker)
        ->and(json_encode($client->postedPayloads, JSON_THROW_ON_ERROR))->not->toContain($marker)
        ->and(runtimeSchemaErrors($runtimePayload))->toBe([])
        ->and($classification->sum('count'))->toBe(2)
        ->and($classification->sum('errorCount'))->toBe(1)
        ->and($classification->sum('inputTokens'))->toBe(80);
});

it('sends each batch with histograms that add up to count, and the schema rejects one with other buckets', function () {
    $client = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true]]);
    runtimePushProject($this->app, $client);
    recordOneRequest($this);
    $this->get('/dashboard')->assertOk();
    $this->artisan('excubia:push-runtime')->assertExitCode(0);
    $this->get('/dashboard')->assertOk();
    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    $first = $client->postedPayloads[0]['runtime'];
    $second = $client->postedPayloads[1]['runtime'];
    $broken = $first;
    $broken['aggregates'][0]['histogram'] = [1, 0, 0];

    expect($first['aggregates'][0])->toMatchArray(['type' => 'request', 'count' => 2])
        ->and(array_sum($first['aggregates'][0]['histogram']))->toBe(2)
        ->and(array_sum($second['aggregates'][0]['histogram']))->toBe($second['aggregates'][0]['count'])
        ->and(runtimeSchemaErrors($first))->toBe([])
        ->and(runtimeSchemaErrors($second))->toBe([])
        ->and(runtimeSchemaErrors($broken))->not->toBe([]);
});

it('sends one server sample per run, named by runtime.server.name, before the buffered data', function () {
    $client = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true]]);
    runtimePushProject($this->app, $client, ['runtime' => ['enabled' => true, 'server' => ['enabled' => true, 'name' => 'web-1']]]);
    $resources = new FakeServerResources;
    $disk = new FakeServerDisk;
    $this->app->instance(ServerResourcesContract::class, $resources);
    $this->app->instance(DiskSpaceContract::class, $disk);
    recordOneRequest($this);

    $this->artisan('excubia:push-runtime')->expectsOutputToContain('Sent 1 runtime batch to excubia-app.')->assertExitCode(0);
    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    $first = $client->postedPayloads[0]['runtime'];
    $server = collect($first['aggregates'])->firstWhere('type', 'server');

    expect($resources->readCount)->toBe(2)
        ->and($client->postedPayloads)->toHaveCount(2)
        ->and(collect($first['aggregates'])->pluck('type')->sort()->values()->all())->toBe(['request', 'server'])
        ->and($server)->toMatchArray(['server' => 'web-1', 'count' => 1, 'loadAverage1' => 0.52, 'cpuCores' => 4, 'memoryUsedMb' => 2048, 'memoryTotalMb' => 8192, 'diskUsedGb' => 30.0, 'diskTotalGb' => 80.0])
        ->and($disk->measuredDirectory)->toBe($this->app->storagePath())
        ->and(collect($client->postedPayloads[1]['runtime']['aggregates'])->pluck('type')->all())->toBe(['server'])
        ->and(runtimeSchemaErrors($first))->toBe([]);
});

it('sends the container limits of a server sample, valid against the schema (#173)', function () {
    $client = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true]]);
    runtimePushProject($this->app, $client, ['runtime' => ['enabled' => true, 'server' => ['enabled' => true, 'name' => 'web-1']]]);
    $this->app->instance(ServerResourcesContract::class, new FakeServerResources(memory: ['usedBytes' => 629145600, 'totalBytes' => 1073741824], cpuLimit: 1.5, isContainer: true));

    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    $runtime = $client->postedPayloads[0]['runtime'];

    expect($runtime['aggregates'][0])->toMatchArray(['type' => 'server', 'cpuCores' => 4, 'cpuLimit' => 1.5, 'memoryUsedMb' => 600, 'memoryTotalMb' => 1024, 'isContainer' => true])
        ->and(runtimeSchemaErrors($runtime))->toBe([])
        ->and(runtimeSchemaErrors(['aggregates' => [[...$runtime['aggregates'][0], 'isContainer' => 'yes']]] + $runtime))->not->toBe([]);
});

it('names the server from EXCUBIA_SERVER_NAME over runtime.server.name', function () {
    putenv('EXCUBIA_SERVER_NAME=web-2');
    $client = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true]]);
    runtimePushProject($this->app, $client, ['runtime' => ['enabled' => true, 'server' => ['enabled' => true, 'name' => 'web-1']]]);
    $this->app->instance(ServerResourcesContract::class, new FakeServerResources);

    $this->artisan('excubia:push-runtime')
        ->doesntExpectOutputToContain('EXCUBIA_SERVER_NAME')
        ->assertExitCode(0);

    expect($client->postedPayloads[0]['runtime']['aggregates'][0])->toMatchArray(['type' => 'server', 'server' => 'web-2']);
});

it('warns and sends the hashed id when EXCUBIA_SERVER_NAME is invalid', function () {
    putenv('EXCUBIA_SERVER_NAME=web 1/eu');
    $client = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true]]);
    runtimePushProject($this->app, $client, ['runtime' => ['enabled' => true, 'server' => ['enabled' => true, 'name' => 'web-1']]]);
    $this->app->instance(ServerResourcesContract::class, new FakeServerResources);

    $this->artisan('excubia:push-runtime')
        ->expectsOutputToContain('EXCUBIA_SERVER_NAME must be 1 to 64 letters, digits, dots, underscores or hyphens; the server is sent as its hashed id.')
        ->assertExitCode(0);

    $server = $client->postedPayloads[0]['runtime']['aggregates'][0];

    expect($server['type'])->toBe('server')
        ->and($server['server'])->toMatch('/^[0-9a-f]{12}$/')
        ->and(json_encode($client->postedPayloads[0], JSON_THROW_ON_ERROR))->not->toContain('web 1/eu');
});

it('never sends the hostname or a path in a server sample, and names an unnamed server by a hash', function () {
    $client = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true]]);
    runtimePushProject($this->app, $client, ['runtime' => ['enabled' => true, 'server' => ['enabled' => true]]]);
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    $runtime = $client->postedPayloads[0]['runtime'];
    $encoded = json_encode($runtime, JSON_THROW_ON_ERROR);
    $hostname = (string) gethostname();

    expect($runtime['aggregates'])->toHaveCount(1)
        ->and($runtime['aggregates'][0]['server'])->toBe(substr(hash('sha256', $hostname.hash('sha256', 'base64:'.base64_encode(str_repeat('k', 32)))), 0, 12))
        ->and($hostname)->not->toBe('')
        ->and($encoded)->not->toContain($hostname)
        ->and($encoded)->not->toContain($this->app->storagePath())
        ->and($encoded)->not->toContain($this->app->basePath())
        ->and($encoded)->not->toContain(sys_get_temp_dir())
        ->and(runtimeSchemaErrors($runtime))->toBe([]);
});

it('takes no server sample when the type is off or not sampled, and still pushes when a reader fails', function (array $server, bool $shouldFail) {
    $client = new FakeHttpClient([RUNTIME_ENDPOINT => ['stored' => true]]);
    runtimePushProject($this->app, $client, ['runtime' => ['enabled' => true, 'server' => $server]]);
    $this->app->instance(ServerResourcesContract::class, new FakeServerResources(shouldFail: $shouldFail));
    recordOneRequest($this);

    $this->artisan('excubia:push-runtime')->assertExitCode(0);

    expect(collect($client->postedPayloads[0]['runtime']['aggregates'])->pluck('type')->all())->toBe(['request']);
})->with([
    'off by default' => [[], false],
    'sample rate 0' => [['enabled' => true, 'sampleRate' => 0], false],
    'reader fails' => [['enabled' => true], true],
]);

it('registers excubia:push-runtime', function () {
    expect(array_keys(Artisan::all()))->toContain('excubia:push-runtime');
});
