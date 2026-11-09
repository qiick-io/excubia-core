<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use JsonSchema\Validator;
use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Jobs\MakeSearchableUniquely;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Laravel\Scout\Searchable;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Services\RuntimeBufferService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Qiick\Excubia\Tests\Support\LaravelTestCase;

require_once dirname(__DIR__).'/Support/FakeScoutClasses.php';

/*
 * Laravel Scout (#221): the `scout` runtime type, with Scout faked under its
 * real class names (tests/Support/FakeScoutClasses.php): it is not a
 * dependency. Index jobs run on the sync queue, which fires the same queue
 * events as a worker.
 */
const SCOUT_MARKER = 'excubiascoutmarker4b9a';

/**
 * A search engine that records nothing and fails when told to.
 */
final class FakeScoutEngine
{
    public static bool $shouldFail = false;

    public function update(mixed $models): void
    {
        if (self::$shouldFail) {
            throw new RuntimeException('engine down for '.SCOUT_MARKER);
        }
    }

    public function delete(mixed $models): void {}

    /**
     * @return array<string, mixed>
     */
    public function search(mixed $builder): array
    {
        return ['hits' => []];
    }
}

final class ScoutPost extends Model
{
    use Searchable;

    protected $table = 'scout_posts';

    protected $guarded = [];

    public $timestamps = false;

    public function searchableUsing(): FakeScoutEngine
    {
        return new FakeScoutEngine;
    }
}

beforeEach(function () {
    FakeScoutEngine::$shouldFail = false;
    config(['queue.default' => 'sync', 'scout.driver' => 'meilisearch']);
    Schema::create('scout_posts', function ($table) {
        $table->id();
        $table->string('title');
    });
});

/**
 * Boot Excubia with `runtime.scout` on (or these runtime keys).
 *
 * @param  array<string, mixed>|null  $runtime
 */
function bootScoutRuntime(Application $app, ?array $runtime = null): void
{
    $app->setBasePath(temporaryProject(['excubia.json' => json_encode(['runtime' => $runtime ?? ['enabled' => true, 'scout' => ['enabled' => true]]], JSON_THROW_ON_ERROR)]));
    $app->useStoragePath(temporaryProject([]));
    $app->register(ExcubiaServiceProvider::class, true);
}

/**
 * @return Collection<string, array<string, mixed>>
 */
function bufferedScout(Application $app): Collection
{
    return bufferedOfType($app, 'scout')->keyBy(fn (array $entry): string => $entry['model'].' '.$entry['operation']);
}

/**
 * Posts whose titles and ids carry the marker.
 *
 * @return EloquentCollection<int, ScoutPost>
 */
function markedPosts(int $count): EloquentCollection
{
    $posts = new EloquentCollection;

    for ($i = 0; $i < $count; $i++) {
        $posts->push(ScoutPost::query()->create(['id' => 987654321 + $i, 'title' => SCOUT_MARKER.' post '.$i]));
    }

    return $posts;
}

it('keeps the scout type off by default and listens only when switched on', function (array $runtime, int $added) {
    $before = count(Event::getListeners(JobProcessing::class));
    bootScoutRuntime($this->app, $runtime);

    expect(count(Event::getListeners(JobProcessing::class)) - $before)->toBeGreaterThanOrEqual($added);

    ScoutPost::search(SCOUT_MARKER)->get();
    $this->app->terminate();

    expect(bufferedScout($this->app))->toHaveCount($added);
})->with([
    'runtime on, scout not switched on' => [['enabled' => true], 0],
    'runtime off' => [['enabled' => false, 'scout' => ['enabled' => true]], 0],
    'scout on' => [['enabled' => true, 'scout' => ['enabled' => true]], 1],
]);

it('counts index and remove jobs per model and engine with their record count, duration and failures', function () {
    bootScoutRuntime($this->app);
    $posts = markedPosts(3);

    $posts->first()->queueMakeSearchable($posts);
    dispatch(new MakeSearchableUniquely($posts->take(2)));
    $posts->first()->queueRemoveFromSearch($posts->take(1));
    dispatch(new MakeSearchable(new EloquentCollection));
    FakeScoutEngine::$shouldFail = true;

    try {
        $posts->first()->queueMakeSearchable($posts->take(1));
    } catch (RuntimeException) {
        // The sync queue rethrows after JobFailed.
    }

    $this->app->terminate();
    $entries = bufferedScout($this->app);
    $validator = new Validator;
    $document = json_decode(json_encode(['bucketSeconds' => 60, 'aggregates' => bufferedRuntime($this->app)], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $validator->validate($document, (object) ['$ref' => 'file://'.dirname(__DIR__, 2).'/docs/runtime-schema.json']);

    expect($entries->keys()->sort()->values()->all())->toBe(['ScoutPost index', 'ScoutPost remove', 'unknown index'])
        ->and($entries->pluck('bucket')->unique()->values()->all())->toBe([gmdate('Y-m-d\TH:i:s\Z', LaravelTestCase::FROZEN_MINUTE)])
        ->and($entries['ScoutPost index'])->toMatchArray(['engine' => 'meilisearch', 'count' => 3, 'errorCount' => 1, 'modelCount' => 6])
        ->and(array_sum($entries['ScoutPost index']['histogram']))->toBe(3)
        ->and($entries['ScoutPost remove'])->toMatchArray(['count' => 1, 'errorCount' => 0, 'modelCount' => 1])
        ->and($entries['unknown index'])->toMatchArray(['count' => 1, 'modelCount' => 0])
        ->and($validator->getErrors())->toBe([]);
});

it('counts searches per model without a duration', function () {
    config(['scout.driver' => 'not a driver!']);
    bootScoutRuntime($this->app);

    ScoutPost::search(SCOUT_MARKER)->get();
    ScoutPost::search('another '.SCOUT_MARKER, fn () => []);
    $this->app->terminate();
    $search = bufferedScout($this->app)['ScoutPost search'];

    expect($search)->toMatchArray(['engine' => 'unknown', 'count' => 2, 'errorCount' => 0, 'modelCount' => 0])
        ->and($search)->not->toHaveKeys(['totalMs', 'maxMs', 'histogram']);
});

it('ignores other jobs', function () {
    bootScoutRuntime($this->app);

    dispatch(static function (): void {});
    $this->app->terminate();

    expect(bufferedScout($this->app))->toHaveCount(0);
});

it('never writes or pushes search queries, record ids or record data', function () {
    putenv('EXCUBIA_APP_URL=https://excubia.example.test/');
    putenv('EXCUBIA_APP_TOKEN=runtime-token');
    $client = new FakeHttpClient(['https://excubia.example.test/api/runtime' => ['stored' => true]]);
    bootScoutRuntime($this->app);
    $this->app->instance(HttpClientContract::class, $client);
    $posts = markedPosts(2);

    $posts->first()->queueMakeSearchable($posts);
    dispatch(new RemoveFromSearch($posts));
    FakeScoutEngine::$shouldFail = true;

    try {
        $posts->first()->queueMakeSearchable($posts);
    } catch (RuntimeException) {
    }

    ScoutPost::search(SCOUT_MARKER.' query')->get();
    $this->app->terminate();

    $raw = (string) file_get_contents($this->app->storagePath('excubia/runtime/'.RuntimeBufferService::BUFFER_FILE));
    $keys = bufferedOfType($this->app, 'scout')->flatMap(fn (array $entry): array => array_keys($entry))->unique()->sort()->values()->all();
    $this->artisan('excubia:push-runtime')->assertExitCode(0);
    $pushed = json_encode($client->postedPayloads, JSON_THROW_ON_ERROR);
    putenv('EXCUBIA_APP_URL');
    putenv('EXCUBIA_APP_TOKEN');

    expect($pushed)->toContain('"scout"')
        ->and($keys)->toBe(['bucket', 'count', 'engine', 'errorCount', 'histogram', 'maxMs', 'model', 'modelCount', 'operation', 'sampleRate', 'totalMs', 'type']);

    foreach ([$raw, $pushed] as $written) {
        expect($written)->not->toContain(SCOUT_MARKER)
            ->and($written)->not->toContain('98765432');
    }
});

it('records nothing at sample rate 0', function () {
    bootScoutRuntime($this->app, ['enabled' => true, 'scout' => ['enabled' => true, 'sampleRate' => 0]]);
    $posts = markedPosts(1);

    $posts->first()->queueMakeSearchable($posts);
    ScoutPost::search('query')->get();
    $this->app->terminate();

    expect(bufferedScout($this->app))->toHaveCount(0);
});
