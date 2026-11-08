<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use JsonSchema\Validator;
use Laravel\Pennant\Events\FeatureResolved;
use Laravel\Pennant\Events\FeatureRetrieved;
use Laravel\Pennant\Events\UnknownFeatureResolved;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Services\RuntimeBufferService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;

require_once dirname(__DIR__).'/Support/FakePennantEvents.php';

/*
 * Laravel Pennant (#225): the `pennant` runtime type, with Pennant's events
 * faked under their real names (tests/Support/FakePennantEvents.php): it is
 * not a dependency.
 */
const PENNANT_MARKER = 'excubiapennantmarker8e3d';

/**
 * Boot Excubia with `runtime.pennant` on (or these runtime keys).
 *
 * @param  array<string, mixed>|null  $runtime
 */
function bootPennantRuntime(Application $app, ?array $runtime = null): void
{
    $app->setBasePath(temporaryProject(['excubia.json' => json_encode(['runtime' => $runtime ?? ['enabled' => true, 'pennant' => ['enabled' => true]]], JSON_THROW_ON_ERROR)]));
    $app->useStoragePath(temporaryProject([]));
    $app->register(ExcubiaServiceProvider::class, true);
}

/**
 * @return Collection<string, array<string, mixed>>
 */
function bufferedFeatures(Application $app): Collection
{
    return bufferedOfType($app, 'pennant')->keyBy(fn (array $entry): string => $entry['feature']);
}

/**
 * A scope full of personal data carrying the marker.
 */
function markedScope(): GenericUser
{
    return new GenericUser(['id' => 987654321, 'email' => PENNANT_MARKER.'@example.test', 'name' => PENNANT_MARKER]);
}

it('keeps the pennant type off by default and listens only when switched on', function (array $runtime, int $added) {
    $before = count(Event::getListeners(FeatureRetrieved::class));
    bootPennantRuntime($this->app, $runtime);

    expect(count(Event::getListeners(FeatureRetrieved::class)))->toBe($before + $added)
        ->and(count(Event::getListeners(UnknownFeatureResolved::class)))->toBe($added);
})->with([
    'runtime on, pennant not switched on' => [['enabled' => true], 0],
    'runtime off' => [['enabled' => false, 'pennant' => ['enabled' => true]], 0],
    'pennant on' => [['enabled' => true, 'pennant' => ['enabled' => true]], 1],
]);

it('counts active and inactive checks, resolutions and unknown features per feature name', function () {
    bootPennantRuntime($this->app);

    event(new FeatureResolved('new-checkout', markedScope(), true));
    event(new FeatureRetrieved('new-checkout', markedScope(), true));
    event(new FeatureRetrieved('new-checkout', null, false));
    event(new FeatureRetrieved('new-checkout', 'team:42', 'variant-b'));
    event(new FeatureRetrieved('App\\Features\\PurchaseButton', markedScope(), 0));
    event(new UnknownFeatureResolved('old-flag', markedScope()));
    event(new FeatureRetrieved('old-flag', markedScope(), false));
    event(new FeatureRetrieved('beta for '.PENNANT_MARKER.'@example.test', markedScope(), true));
    $this->app->terminate();
    $entries = bufferedFeatures($this->app);
    $hashed = substr(hash('sha256', 'beta for '.PENNANT_MARKER.'@example.test'), 0, 12);
    $validator = new Validator;
    $document = json_decode(json_encode(['bucketSeconds' => 60, 'aggregates' => bufferedRuntime($this->app)], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $validator->validate($document, (object) ['$ref' => 'file://'.dirname(__DIR__, 2).'/docs/runtime-schema.json']);

    expect($entries->keys()->all())->toEqualCanonicalizing(['App\\Features\\PurchaseButton', $hashed, 'new-checkout', 'old-flag'])
        ->and($entries['new-checkout'])->toMatchArray(['count' => 4, 'errorCount' => 0, 'activeCount' => 2, 'inactiveCount' => 1, 'resolvedCount' => 1, 'unknownCount' => 0])
        ->and($entries['App\\Features\\PurchaseButton'])->toMatchArray(['count' => 1, 'activeCount' => 1, 'inactiveCount' => 0])
        ->and($entries['old-flag'])->toMatchArray(['count' => 2, 'inactiveCount' => 1, 'unknownCount' => 1])
        ->and($entries[$hashed])->toMatchArray(['count' => 1, 'activeCount' => 1])
        ->and($validator->getErrors())->toBe([]);
});

it('never writes or pushes the scope, the value or an unusual feature name', function () {
    putenv('EXCUBIA_APP_URL=https://excubia.example.test/');
    putenv('EXCUBIA_APP_TOKEN=runtime-token');
    $client = new FakeHttpClient(['https://excubia.example.test/api/runtime' => ['stored' => true]]);
    bootPennantRuntime($this->app);
    $this->app->instance(HttpClientContract::class, $client);

    event(new FeatureResolved('new-checkout', markedScope(), 'variant-'.PENNANT_MARKER));
    event(new FeatureRetrieved('new-checkout', markedScope(), ['plan' => PENNANT_MARKER]));
    event(new FeatureRetrieved('user '.PENNANT_MARKER.'@example.test', 'user:987654321', true));
    event(new UnknownFeatureResolved('flag '.PENNANT_MARKER, markedScope()));
    $this->app->terminate();

    $raw = (string) file_get_contents($this->app->storagePath('excubia/runtime/'.RuntimeBufferService::BUFFER_FILE));
    $keys = bufferedOfType($this->app, 'pennant')->flatMap(fn (array $entry): array => array_keys($entry))->unique()->sort()->values()->all();
    $this->artisan('excubia:push-runtime')->assertExitCode(0);
    $pushed = json_encode($client->postedPayloads, JSON_THROW_ON_ERROR);
    putenv('EXCUBIA_APP_URL');
    putenv('EXCUBIA_APP_TOKEN');

    expect($pushed)->toContain('"pennant"')
        ->and($keys)->toBe(['activeCount', 'bucket', 'count', 'errorCount', 'feature', 'inactiveCount', 'resolvedCount', 'sampleRate', 'type', 'unknownCount']);

    foreach ([$raw, $pushed] as $written) {
        expect($written)->not->toContain(PENNANT_MARKER)
            ->and($written)->not->toContain('@')
            ->and($written)->not->toContain('987654321')
            ->and($written)->not->toContain('variant');
    }
});

it('records nothing at sample rate 0', function () {
    bootPennantRuntime($this->app, ['enabled' => true, 'pennant' => ['enabled' => true, 'sampleRate' => 0]]);

    event(new FeatureRetrieved('new-checkout', null, true));
    $this->app->terminate();

    expect(bufferedFeatures($this->app))->toHaveCount(0);
});
