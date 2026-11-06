<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Qiick\Excubia\Checks\LaravelRedisMemoryCheck;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LaravelPostureData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Laravel\LaravelPostureService;

/**
 * Redis manager stand-in for `INFO memory`: `$replies` holds the reply of
 * `command('info', ['memory'])` per connection name, `'throws'` for a
 * connection that is down. No Redis server is needed.
 */
final class FakeMemoryRedis
{
    /**
     * @var list<string>
     */
    public array $connectedNames = [];

    private string $name = '';

    /**
     * @param  array<string, mixed>  $replies
     */
    public function __construct(private readonly array $replies) {}

    public function connection(?string $name = null): self
    {
        $name ??= 'default';
        $this->connectedNames[] = $name;

        if (($this->replies[$name] ?? null) === 'throws') {
            throw new RuntimeException('Connection refused [tcp://redis-host-sentinel:6379] password-sentinel');
        }

        $connection = clone $this;
        $connection->name = $name;

        return $connection;
    }

    /**
     * @param  list<mixed>  $parameters
     */
    public function command(string $method, array $parameters = []): mixed
    {
        if ($method !== 'info' || $parameters !== ['memory']) {
            throw new RuntimeException('Unexpected command '.$method);
        }

        return $this->replies[$this->name] ?? false;
    }
}

/**
 * The recorded `INFO memory` text reply (`bounded`: 92 of 100 MiB with
 * allkeys-lru, `unbounded`: 1.25 MiB, maxmemory 0, noeviction).
 */
function redisInfoText(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__).'/Fixtures/Responses/Redis/info-memory-'.$name.'.txt');
}

/**
 * The recorded reply as phpredis returns it: one flat array of strings.
 *
 * @return array<string, string>
 */
function phpredisInfo(string $name): array
{
    $info = [];

    foreach (preg_split('/\r?\n/', redisInfoText($name)) ?: [] as $line) {
        if (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $info[$key] = $value;
        }
    }

    return $info;
}

/**
 * The recorded reply as Predis returns it: nested under the section name.
 *
 * @return array{Memory: array<string, string>}
 */
function predisInfo(string $name): array
{
    return ['Memory' => phpredisInfo($name)];
}

/**
 * Redis-backed cache (connection `cache`), queue (`default`) and session
 * (`sessions`), with hosts and passwords that must never reach the report.
 *
 * @param  array<string, mixed>  $replies
 */
function redisMemoryApp(array $replies): FakeMemoryRedis
{
    app()->setBasePath(temporaryProject([
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [], "packages-dev": []}',
    ]));
    config([
        'database.redis' => [
            'client' => 'phpredis',
            'default' => ['host' => 'redis-host-sentinel', 'password' => 'password-sentinel', 'port' => 6379],
            'cache' => ['host' => 'redis-host-sentinel', 'password' => 'password-sentinel', 'port' => 6380],
            'sessions' => ['url' => 'redis://user:password-sentinel@redis-host-sentinel:6381'],
        ],
        'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache'],
        'cache.default' => 'redis',
        'queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default'],
        'queue.default' => 'redis',
        'session.driver' => 'redis',
        'session.connection' => 'sessions',
    ]);
    $redis = new FakeMemoryRedis($replies);
    app()->instance('redis', $redis);

    return $redis;
}

function redisMemoryResult(?ExcubiaConfigurationData $configuration = null, bool $isBooted = true): CheckResultData
{
    $configuration ??= ExcubiaConfigurationData::defaults();
    $snapshot = new ProjectSnapshotData(null, null, [], [], null);

    if ($isBooted) {
        $snapshot = $snapshot->withLaravelPosture((new LaravelPostureService)->collect(app(), configuration: $configuration));
    }

    return (new LaravelRedisMemoryCheck)->run($snapshot, $configuration);
}

/**
 * @param  array<string, mixed>  $settings
 */
function redisMemoryConfiguration(array $settings): ExcubiaConfigurationData
{
    return (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => json_encode(['checks' => ['laravel-redis-memory' => $settings]], JSON_THROW_ON_ERROR),
    ]));
}

describe('laravel-redis-memory', function () {
    it('is skipped without a posture or a hand-built posture without Redis', function () {
        $standalone = redisMemoryResult(isBooted: false);
        $posture = new LaravelPostureData('production', false, true, true, true, false, [], 0, null, true, null, new DateTimeImmutable);
        $handBuilt = (new LaravelRedisMemoryCheck)->run((new ProjectSnapshotData(null, null, [], [], null))->withLaravelPosture($posture), ExcubiaConfigurationData::defaults());

        expect($standalone->status->value)->toBe('skipped')
            ->and($standalone->findings[0]->message)->toContain('requires Artisan')
            ->and($handBuilt->status->value)->toBe('skipped');
    });

    it('is skipped when no cache store, queue connection or session uses Redis', function () {
        $redis = redisMemoryApp([]);
        config(['cache.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'file']);
        $result = redisMemoryResult();

        expect($result->status->value)->toBe('skipped')
            ->and($result->findings[0]->message)->toBe('No Redis connection is used by the default cache store, queue connection or session.')
            ->and($redis->connectedNames)->toBe([]);
    });

    it('reads the cache, queue and session connections once each and passes below 80 %', function () {
        $redis = redisMemoryApp(['cache' => phpredisInfo('bounded'), 'default' => phpredisInfo('bounded'), 'sessions' => phpredisInfo('bounded')]);
        $result = redisMemoryResult(redisMemoryConfiguration(['warnAtPercentUsed' => 95, 'failAtPercentUsed' => 99]));

        expect($result->status->value)->toBe('pass')
            ->and($result->findings)->toBe([])
            ->and($redis->connectedNames)->toContain('cache', 'default', 'sessions');
    });

    it('warns at medium from 80 % and fails at high from 90 % of maxmemory, in the phpredis and the Predis shape', function () {
        redisMemoryApp(['cache' => phpredisInfo('bounded'), 'default' => predisInfo('bounded')]);
        config(['session.driver' => 'file']);
        $failing = redisMemoryResult();
        $warning = redisMemoryResult(redisMemoryConfiguration(['warnAtPercentUsed' => 80, 'failAtPercentUsed' => 95]));

        expect($failing->status->value)->toBe('fail')
            ->and(array_map(fn ($finding) => [$finding->severity->value, $finding->subject], $failing->findings))->toBe([['high', 'cache'], ['high', 'default']])
            ->and($failing->findings[0]->message)->toBe('Redis connection cache uses 92 % of its maxmemory.')
            ->and($failing->findings[0]->details)->toBe(['connection' => 'cache', 'usedMb' => 92.0, 'maxMb' => 100.0, 'policy' => 'allkeys-lru'])
            ->and($failing->findings[1]->details)->toBe(['connection' => 'default', 'usedMb' => 92.0, 'maxMb' => 100.0, 'policy' => 'allkeys-lru'])
            ->and($warning->status->value)->toBe('warn')
            ->and(array_map(fn ($finding) => $finding->severity->value, $warning->findings))->toBe(['medium', 'medium']);
    });

    it('warns at low for an unbounded connection and for noeviction on the cache connection only', function () {
        $noEviction = ['used_memory' => 1_048_576, 'maxmemory' => 104_857_600, 'maxmemory_policy' => 'noeviction'];
        redisMemoryApp(['cache' => $noEviction, 'default' => $noEviction, 'sessions' => redisInfoText('unbounded')]);
        $result = redisMemoryResult();

        expect($result->status->value)->toBe('warn')
            ->and(array_map(fn ($finding) => [$finding->severity->value, $finding->subject], $result->findings))->toBe([['low', 'cache'], ['low', 'sessions']])
            ->and($result->findings[0]->message)->toBe('Redis connection cache holds the cache with the noeviction policy, so writes fail once maxmemory is reached.')
            ->and($result->findings[1]->message)->toBe('Redis connection sessions has no maxmemory limit, so it can grow until the server runs out of memory.')
            ->and($result->findings[1]->details)->toBe(['connection' => 'sessions', 'usedMb' => 1.25, 'maxMb' => null, 'policy' => 'noeviction']);
    });

    it('leaves out connections that are down or answer nothing, and skips when none answers', function () {
        redisMemoryApp(['cache' => 'throws', 'default' => false, 'sessions' => predisInfo('bounded')]);
        $partial = redisMemoryResult();
        redisMemoryApp(['cache' => 'throws', 'default' => 'throws', 'sessions' => ['unexpected' => 'reply']]);
        $none = redisMemoryResult();

        expect(array_map(fn ($finding) => $finding->subject, $partial->findings))->toBe(['sessions'])
            ->and($none->status->value)->toBe('skipped')
            ->and($none->findings[0]->message)->toBe('No Redis connection answered INFO memory.');
    });

    it('reads checks.laravel-redis-memory.connections instead of the discovered ones', function () {
        $redis = redisMemoryApp(['reports' => phpredisInfo('bounded'), 'cache' => phpredisInfo('bounded')]);
        $result = redisMemoryResult(redisMemoryConfiguration(['connections' => ['reports']]));

        expect(array_map(fn ($finding) => $finding->subject, $result->findings))->toBe(['reports'])
            ->and($redis->connectedNames)->not->toContain('sessions');
    });

    it('validates the thresholds and connection names', function () {
        expect(ExcubiaConfigurationData::defaults()->redisMemoryWarnAtPercentUsed)->toBe(80)
            ->and(ExcubiaConfigurationData::defaults()->redisMemoryFailAtPercentUsed)->toBe(90)
            ->and(ExcubiaConfigurationData::defaults()->redisMemoryConnectionNames)->toBe([])
            ->and(fn () => redisMemoryConfiguration(['warnAtPercentUsed' => 95, 'failAtPercentUsed' => 90]))->toThrow(InvalidConfigurationException::class, 'laravel-redis-memory needs 1 <= warnAtPercentUsed <= failAtPercentUsed <= 100.')
            ->and(fn () => redisMemoryConfiguration(['connections' => 'cache']))->toThrow(InvalidConfigurationException::class)
            ->and(fn () => redisMemoryConfiguration(['ttl' => 1]))->toThrow(InvalidConfigurationException::class)
            ->and(fn () => new ExcubiaConfigurationData(null, true, 24, 180, 180, true, [], null, 24, redisMemoryConnectionNames: ['']))->toThrow(InvalidConfigurationException::class, 'checks.laravel-redis-memory.connections entries must be non-empty names.');
    });

    it('keeps hosts, ports, passwords and the rest of INFO memory out of the report', function () {
        $info = phpredisInfo('bounded') + ['maxmemory_policy' => 'allkeys-lru'];
        $info['mem_allocator'] = 'allocator-sentinel';
        redisMemoryApp(['cache' => $info, 'default' => 'throws', 'sessions' => ['used_memory' => '1', 'maxmemory' => '0', 'maxmemory_policy' => 'policy sentinel with spaces']]);

        Artisan::call('excubia:check', ['--format' => 'json', '--no-banner' => true, '--fail-on' => 'none', '--only' => 'laravel-redis-memory']);
        $output = Artisan::output();

        expect($output)->toContain('"connection": "cache"', '"connection": "sessions"', '"policy": null')
            ->not->toContain('sentinel')
            ->not->toContain('6379')
            ->not->toContain('jemalloc');
    });
});
