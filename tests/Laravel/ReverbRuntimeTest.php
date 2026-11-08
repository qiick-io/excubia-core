<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use JsonSchema\Validator;
use Laravel\Reverb\Application as ReverbApplication;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Events\ChannelCreated;
use Laravel\Reverb\Events\ChannelRemoved;
use Laravel\Reverb\Events\ConnectionPruned;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Events\MessageSent;
use Laravel\Reverb\Protocols\Pusher\Channels\Channel;
use Laravel\Reverb\Protocols\Pusher\Channels\ChannelConnection;
use Qiick\Excubia\Checks\LaravelReverbCheck;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\LaravelPostureService;
use Qiick\Excubia\Services\RuntimeBufferService;
use React\EventLoop\Loop;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

require_once dirname(__DIR__).'/Support/FakeReverbEvents.php';

/*
 * Laravel Reverb (#215): the `reverb` runtime type and the `laravel-reverb`
 * check, with Reverb's events faked under their real names
 * (tests/Support/FakeReverbEvents.php): laravel/reverb is not a dependency.
 */
const REVERB_MARKER = 'excubia-reverb-marker-7d3a';

/**
 * Boot Excubia with `runtime.reverb` on, plus these keys.
 *
 * @param  array<string, mixed>  $runtime
 */
function bootReverbRuntime(Application $app, array $runtime = []): void
{
    bootReverbProject($app, ['enabled' => true, 'reverb' => ['enabled' => true], ...$runtime]);
}

/**
 * @param  array<string, mixed>  $runtime
 */
function bootReverbProject(Application $app, array $runtime): void
{
    $app->setBasePath(temporaryProject(['excubia.json' => json_encode(['runtime' => $runtime], JSON_THROW_ON_ERROR)]));
    $app->useStoragePath(temporaryProject([]));
    $app->register(ExcubiaServiceProvider::class, true);
}

/**
 * Bind Reverb's application provider and a channel manager scoped like
 * Reverb's own (`for()` sets the current app) that holds these connection
 * counts per app id.
 *
 * @param  list<ReverbApplication>  $applications
 * @param  array<string, int>  $connectionCounts
 */
function bindReverbServer(Application $app, array $applications, array $connectionCounts = []): object
{
    $app->instance('Laravel\Reverb\Contracts\ApplicationProvider', new class($applications)
    {
        /** @param list<ReverbApplication> $applications */
        public function __construct(private array $applications) {}

        public function all(): Collection
        {
            return collect($this->applications);
        }
    });

    $channels = new class($connectionCounts)
    {
        public ?ReverbApplication $application = null;

        /** @param array<string, int> $connectionCounts */
        public function __construct(private array $connectionCounts) {}

        public function for(ReverbApplication $application): self
        {
            $this->application = $application;

            return $this;
        }

        public function app(): ?ReverbApplication
        {
            return $this->application;
        }

        /** @return array<string, string> */
        public function connections(): array
        {
            return array_fill_keys(array_map(strval(...), range(1, $this->connectionCounts[$this->application?->id()] ?? 0)), REVERB_MARKER);
        }
    };
    $app->instance('Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager', $channels);

    return $channels;
}

/**
 * Start `reverb:start` and run the periodic timer Excubia added to its loop.
 */
function reverbTick(): void
{
    foreach (Loop::get()->timers as $timer) {
        ($timer['callback'])();
    }
}

function reverbStart(): void
{
    Loop::get()->timers = [];
    Event::dispatch(new CommandStarting('reverb:start', new ArrayInput([]), new NullOutput));
}

/**
 * A Pusher frame as Reverb's EventHandler and exceptions encode it.
 *
 * @param  array<string, mixed>  $data
 */
function pusherFrame(string $event, array $data = [], ?string $channel = null): string
{
    return (string) json_encode(array_filter(['event' => $event, 'data' => $data === [] ? null : json_encode($data), 'channel' => $channel]));
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function bufferedReverb(Application $app): Collection
{
    return bufferedOfType($app, 'reverb');
}

/**
 * @return list<string>
 */
function reverbRuntimeSchemaErrors(Application $app): array
{
    $validator = new Validator;
    $document = json_decode(json_encode(['bucketSeconds' => 60, 'aggregates' => bufferedRuntime($app)], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $validator->validate($document, (object) ['$ref' => 'file://'.dirname(__DIR__, 2).'/docs/runtime-schema.json']);

    return array_map(fn (array $error): string => $error['property'].': '.$error['message'], $validator->getErrors());
}

/**
 * One client connecting: Reverb sends `pusher:connection_established`.
 */
function reverbConnect(ReverbApplication $application): Connection
{
    $connection = new Connection($application, 'https://'.REVERB_MARKER.'.example.test', '9876.'.REVERB_MARKER);
    Event::dispatch(new MessageSent($connection, pusherFrame('pusher:connection_established', ['socket_id' => $connection->id(), 'activity_timeout' => 30])));

    return $connection;
}

it('keeps the reverb type off by default and listens to Reverb only when switched on', function (array $runtime, int $added) {
    $before = count(Event::getListeners(MessageSent::class));
    bootReverbProject($this->app, $runtime);

    expect(count(Event::getListeners(MessageSent::class)))->toBe($before + $added);
})->with([
    'runtime on, reverb not switched on' => [['enabled' => true], 0],
    'runtime off' => [['enabled' => false, 'reverb' => ['enabled' => true]], 0],
    'reverb on' => [['enabled' => true, 'reverb' => ['enabled' => true]], 1],
]);

it('records connections, messages, errors and channels by prefix in one entry per server and app, flushed by a timer on the Reverb loop', function () {
    putenv('EXCUBIA_SERVER_NAME=ws-1');
    bootReverbRuntime($this->app);
    $application = new ReverbApplication('123456');
    $channels = bindReverbServer($this->app, [$application], ['123456' => 2]);
    reverbStart();

    $first = reverbConnect($application);
    $second = reverbConnect($application);
    $rejected = new Connection($application);
    Event::dispatch(new MessageSent($rejected, pusherFrame('pusher:error', ['code' => 4004, 'message' => 'Application is over connection quota'])));
    Event::dispatch(new MessageReceived($first, pusherFrame('pusher:subscribe', ['channel' => 'private-orders.'.REVERB_MARKER])));
    $channels->for($application);

    foreach (['private-orders.1', 'private-cache-totals', 'presence-room.1', 'orders', 'cache-ticker'] as $name) {
        Event::dispatch(new ChannelCreated(new Channel($name)));
    }

    Event::dispatch(new ChannelRemoved(new Channel('presence-room.1')));
    Event::dispatch(new MessageSent($first, pusherFrame('App\Events\OrderShipped', ['order' => REVERB_MARKER], 'private-orders.1')));
    Event::dispatch(new MessageSent($first, pusherFrame('pusher:error', ['code' => 4301, 'message' => 'Rate limit exceeded'])));
    Event::dispatch(new MessageSent($second, (string) json_encode(['event' => 'pusher:error', 'data' => json_encode(['code' => 4201, 'message' => 'Pong reply not received in time'])])));
    Event::dispatch(new ConnectionPruned(new ChannelConnection($second)));
    putenv('EXCUBIA_SERVER_NAME');

    expect(Loop::get()->timers)->toHaveCount(1)
        ->and(Loop::get()->timers[0]['interval'])->toBe(60.0)
        ->and(bufferedReverb($this->app))->toHaveCount(0);

    reverbTick();
    $entries = bufferedReverb($this->app);

    expect($entries)->toHaveCount(1)
        ->and($entries->first())->toMatchArray([
            'server' => 'ws-1',
            'appId' => '123456',
            'count' => 15,
            'errorCount' => 1,
            'connectionsOpened' => 2,
            'connectionsRejected' => 1,
            'connectionsPruned' => 1,
            'maxConnections' => 2,
            'messagesReceived' => 1,
            'messagesSent' => 6,
            'publicChannelsCreated' => 2,
            'privateChannelsCreated' => 2,
            'presenceChannelsCreated' => 1,
            'publicChannelsRemoved' => 0,
            'privateChannelsRemoved' => 0,
            'presenceChannelsRemoved' => 1,
        ])
        ->and(reverbRuntimeSchemaErrors($this->app))->toBe([]);
});

it('adds the timer to reverb:start only, and flushes when the command terminates', function () {
    bootReverbRuntime($this->app);
    $application = new ReverbApplication('123456');
    Loop::get()->timers = [];

    Event::dispatch(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput));
    reverbConnect($application);

    expect(Loop::get()->timers)->toBe([])
        ->and(bufferedReverb($this->app))->toHaveCount(0);

    $this->app->terminate();

    expect(bufferedReverb($this->app)->sum('connectionsOpened'))->toBe(1);
});

it('keeps a short app id, hashes a long one, and samples the connections of every configured app', function () {
    bootReverbRuntime($this->app);
    $long = str_repeat('a', 40).REVERB_MARKER;
    bindReverbServer($this->app, [new ReverbApplication('main-app'), new ReverbApplication($long)], ['main-app' => 3, $long => 1]);
    reverbStart();

    reverbConnect(new ReverbApplication($long));
    reverbTick();
    $entries = bufferedReverb($this->app)->keyBy('appId');

    expect($entries->keys()->all())->toEqualCanonicalizing(['main-app', substr(hash('sha256', $long), 0, 12)])
        ->and($entries['main-app']['maxConnections'])->toBe(3)
        ->and($entries[substr(hash('sha256', $long), 0, 12)])->toMatchArray(['maxConnections' => 1, 'connectionsOpened' => 1]);
});

it('never writes message payloads, channel names, socket ids, origins, app keys or secrets to the buffer', function () {
    bootReverbRuntime($this->app);
    $application = new ReverbApplication('123456', 'key-'.REVERB_MARKER, 'secret-'.REVERB_MARKER, [REVERB_MARKER.'.example.test']);
    $channels = bindReverbServer($this->app, [$application], ['123456' => 1]);
    reverbStart();

    $connection = reverbConnect($application);
    Event::dispatch(new MessageReceived($connection, pusherFrame('client-typing', ['user' => REVERB_MARKER.'@example.test'], 'presence-chat.'.REVERB_MARKER)));
    $channels->for($application);
    Event::dispatch(new ChannelCreated(new Channel('presence-chat.'.REVERB_MARKER)));
    Event::dispatch(new MessageSent($connection, pusherFrame('pusher:error', ['code' => 4009, 'message' => REVERB_MARKER])));
    Event::dispatch(new ChannelRemoved(new Channel('private-encrypted-'.REVERB_MARKER)));
    reverbTick();

    $raw = (string) file_get_contents($this->app->storagePath('excubia/runtime/'.RuntimeBufferService::BUFFER_FILE));
    $hostname = (string) gethostname();

    expect($raw)->not->toBe('')
        ->and($raw)->not->toContain(REVERB_MARKER)
        ->and($raw)->not->toContain('example.test')
        ->and($raw)->not->toContain('9876')
        ->and($raw)->not->toContain('chat')
        ->and($hostname === '' || ! str_contains($raw, $hostname))->toBeTrue()
        ->and(array_keys(bufferedReverb($this->app)->first()))->toEqualCanonicalizing([
            'type', 'bucket', 'sampleRate', 'server', 'appId', 'count', 'errorCount',
            'connectionsOpened', 'connectionsPruned', 'connectionsRejected', 'maxConnections', 'messagesReceived', 'messagesSent',
            'publicChannelsCreated', 'privateChannelsCreated', 'presenceChannelsCreated', 'publicChannelsRemoved', 'privateChannelsRemoved', 'presenceChannelsRemoved',
        ])
        ->and(bufferedReverb($this->app)->first())->toMatchArray(['errorCount' => 1, 'presenceChannelsCreated' => 1, 'privateChannelsRemoved' => 1]);
});

it('records nothing at sample rate 0', function () {
    bootReverbRuntime($this->app, ['reverb' => ['enabled' => true, 'sampleRate' => 0]]);
    $application = new ReverbApplication('123456');
    bindReverbServer($this->app, [$application], ['123456' => 5]);
    reverbStart();

    $connection = reverbConnect($application);
    Event::dispatch(new MessageReceived($connection, '{}'));
    Event::dispatch(new ChannelCreated(new Channel('orders')));
    Event::dispatch(new ConnectionPruned(new ChannelConnection($connection)));
    reverbTick();

    expect(bufferedReverb($this->app))->toHaveCount(0);
});

it('never lets an exception inside the Reverb listeners reach the server', function () {
    bootReverbRuntime($this->app);
    $this->app->bind('Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager', function (): never {
        throw new RuntimeException(REVERB_MARKER);
    });
    $connection = new class(new ReverbApplication('123456')) extends Connection
    {
        public function app(): ReverbApplication
        {
            throw new RuntimeException(REVERB_MARKER);
        }
    };
    reverbStart();

    Event::dispatch(new MessageSent($connection, '{}'));
    Event::dispatch(new MessageReceived($connection, '{}'));
    Event::dispatch(new ConnectionPruned(new ChannelConnection($connection)));
    Event::dispatch(new ChannelCreated(new Channel('orders')));
    reverbTick();

    expect(bufferedReverb($this->app))->toHaveCount(0);
});

/**
 * `laravel-reverb` on the booted test app.
 */
function reverbCheckResult(bool $isBooted = true): CheckResultData
{
    $snapshot = new ProjectSnapshotData(null, null, [], [], null);

    if ($isBooted) {
        $snapshot = $snapshot->withLaravelPosture((new LaravelPostureService)->collect(app(), checkIds: ['laravel-reverb']));
    }

    return (new LaravelReverbCheck)->run($snapshot, ExcubiaConfigurationData::defaults());
}

/**
 * Make Reverb the default broadcast driver with these connection options.
 *
 * @param  array<string, mixed>  $options
 */
function useReverbBroadcasting(array $options): void
{
    config([
        'reverb' => ['default' => 'reverb'],
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'key-'.REVERB_MARKER, 'secret' => 'secret-'.REVERB_MARKER, 'app_id' => '123456', 'options' => $options],
    ]);
}

/**
 * A local TCP port: listening (the server stays open while the returned
 * resource lives) or closed again.
 *
 * @return array{0: int, 1: resource|null}
 */
function localReverbPort(bool $isListening): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    if (! $isListening) {
        fclose($server);
        $server = null;
    }

    return [$port, $server];
}

describe('laravel-reverb', function () {
    it('is skipped without a posture, without laravel/reverb, when Reverb is not the broadcast driver and without a host', function () {
        expect(reverbCheckResult(isBooted: false)->status)->toBe(FindingStatusEnum::Skipped)
            ->and(reverbCheckResult()->findings[0]->message)->toBe('laravel/reverb is not installed.');

        config(['reverb' => ['default' => 'reverb'], 'broadcasting.default' => 'log', 'broadcasting.connections.log' => ['driver' => 'log']]);
        expect(reverbCheckResult()->findings[0]->message)->toBe('Reverb is not the default broadcast driver.');

        useReverbBroadcasting(['port' => 8080, 'scheme' => 'http']);
        expect(reverbCheckResult()->findings[0]->message)->toBe('The Reverb broadcast connection has no host configured.');
    });

    it('passes at info with the connect time when the server answers, the port read from the environment', function () {
        [$port, $server] = localReverbPort(isListening: true);
        useReverbBroadcasting(['host' => '127.0.0.1', 'port' => (string) $port, 'scheme' => 'http']);

        $result = reverbCheckResult();
        $finding = $result->findings[0];
        fclose($server);

        expect($finding->status)->toBe(FindingStatusEnum::Pass)
            ->and($finding->severity)->toBe(SeverityEnum::Info)
            ->and($finding->subject)->toBe('reverb')
            ->and($finding->details['isReachable'])->toBeTrue()
            ->and($finding->details['latencyMs'])->toBeFloat()->toBeGreaterThanOrEqual(0.0)
            ->and(array_keys($finding->details))->toBe(['isReachable', 'latencyMs']);
    });

    it('fails at high when nothing answers, and never reports the host, port or credentials', function () {
        [$port] = localReverbPort(isListening: false);
        useReverbBroadcasting(['host' => '127.0.0.1', 'port' => $port, 'scheme' => 'http']);

        $result = reverbCheckResult();
        $json = json_encode($result, JSON_THROW_ON_ERROR);

        expect($result->status)->toBe(FindingStatusEnum::Fail)
            ->and($result->findings[0]->severity)->toBe(SeverityEnum::High)
            ->and($result->findings[0]->details)->toBe(['isReachable' => false, 'latencyMs' => null])
            ->and($json)->not->toContain('127.0.0.1')
            ->and($json)->not->toContain((string) $port)
            ->and($json)->not->toContain(REVERB_MARKER);
    });
});
