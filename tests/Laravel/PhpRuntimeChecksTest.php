<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Qiick\Excubia\Checks\PhpOpcacheCheck;
use Qiick\Excubia\Checks\PhpProductionIniCheck;
use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Contracts\PhpRuntimeContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LaravelPostureData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Laravel\LaravelPostureService;
use Qiick\Excubia\Laravel\PhpWebRuntimeSnapshotService;
use Qiick\Excubia\Services\PhpRuntimeService;

/**
 * Stand-in for the PHP of one SAPI: a production-ready runtime unless a
 * test overrides a value.
 */
final class FakePhpRuntime implements PhpRuntimeContract
{
    public int $reads = 0;

    /**
     * @param  array<string, bool|float|int|null>  $overrides
     */
    public function __construct(private readonly array $overrides = []) {}

    public function read(): array
    {
        $this->reads++;

        /** @var array{isOpcacheEnabled: bool, hitRate: float|null, memoryUsedPercent: float|null, cachedScripts: int|null, isValidateTimestampsEnabled: bool, isDisplayErrorsEnabled: bool, isExposePhpEnabled: bool, isLogErrorsEnabled: bool} */
        return $this->overrides + [
            'isOpcacheEnabled' => true,
            'hitRate' => 99.5,
            'memoryUsedPercent' => 40.0,
            'cachedScripts' => 1500,
            'isValidateTimestampsEnabled' => false,
            'isDisplayErrorsEnabled' => false,
            'isExposePhpEnabled' => false,
            'isLogErrorsEnabled' => true,
        ];
    }
}

/**
 * Runs one check on the posture of the booted app, with `$cli` as the PHP
 * of this (CLI) process.
 *
 * @param  array<string, bool|float|int|null>  $cli
 */
function phpRuntimeResult(HealthCheckContract $check, array $cli = [], string $environment = 'production', bool $isBooted = true): CheckResultData
{
    app()['env'] = $environment;
    $snapshot = new ProjectSnapshotData(null, null, [], [], null);

    if ($isBooted) {
        $snapshot = $snapshot->withLaravelPosture((new LaravelPostureService(phpRuntime: new FakePhpRuntime($cli)))->collect(app()));
    }

    return $check->run($snapshot, ExcubiaConfigurationData::defaults());
}

/**
 * Stores a web-SAPI snapshot as the health endpoint would, `$minutesAgo`
 * minutes ago.
 *
 * @param  array<string, bool|float|int|null>  $web
 */
function recordWebRuntime(array $web, int $minutesAgo = 5): FakePhpRuntime
{
    $runtime = new FakePhpRuntime($web);
    app()->instance(PhpRuntimeContract::class, $runtime);
    PhpWebRuntimeSnapshotService::record(app(), time() - $minutesAgo * 60);

    return $runtime;
}

beforeEach(function () {
    app('cache')->forget(PhpWebRuntimeSnapshotService::CACHE_KEY);
    app()->forgetInstance(PhpRuntimeContract::class);
});

describe('php-opcache', function () {
    it('is skipped without a posture', function () {
        $result = phpRuntimeResult(new PhpOpcacheCheck, isBooted: false);

        expect($result->status->value)->toBe('skipped')
            ->and($result->findings[0]->message)->toContain('requires Artisan');
    });

    it('warns at medium when OPcache is disabled in production and says the CLI was checked', function () {
        $result = phpRuntimeResult(new PhpOpcacheCheck, ['isOpcacheEnabled' => false, 'hitRate' => null, 'memoryUsedPercent' => null, 'cachedScripts' => null]);
        $finding = $result->findings[0];

        expect($result->status->value)->toBe('warn')
            ->and($result->findings)->toHaveCount(1)
            ->and($finding->severity->value)->toBe('medium')
            ->and($finding->subject)->toBe('opcache')
            ->and($finding->message)->toContain('OPcache is disabled in production', 'CLI SAPI')
            ->and($finding->fixHint)->toContain('opcache.enable_cli')
            ->and($finding->details)->toBe(['sapi' => 'cli', 'ageMinutes' => null, 'isOpcacheEnabled' => false, 'hitRate' => null, 'memoryUsedPercent' => null, 'cachedScripts' => null, 'isValidateTimestampsEnabled' => false]);
    });

    it('passes with OPcache disabled outside production', function () {
        expect(phpRuntimeResult(new PhpOpcacheCheck, ['isOpcacheEnabled' => false, 'isValidateTimestampsEnabled' => true], 'local')->status->value)->toBe('pass');
    });

    it('warns at low for validate_timestamps in production only', function () {
        $production = phpRuntimeResult(new PhpOpcacheCheck, ['isValidateTimestampsEnabled' => true]);
        $staging = phpRuntimeResult(new PhpOpcacheCheck, ['isValidateTimestampsEnabled' => true], 'staging');

        expect($production->findings)->toHaveCount(1)
            ->and($production->findings[0]->subject)->toBe('opcache.validate_timestamps')
            ->and($production->findings[0]->severity->value)->toBe('low')
            ->and($staging->status->value)->toBe('pass');
    });

    it('ignores the hit rate and memory of a CLI process, whose cache starts empty', function () {
        expect(phpRuntimeResult(new PhpOpcacheCheck, ['hitRate' => 20.0, 'memoryUsedPercent' => 95.0])->status->value)->toBe('pass');
    });

    it('prefers the web snapshot and warns at low for a poor hit rate or full memory', function () {
        recordWebRuntime(['hitRate' => 85.25, 'memoryUsedPercent' => 92.5], 12);
        $result = phpRuntimeResult(new PhpOpcacheCheck, ['isOpcacheEnabled' => false]);

        expect($result->status->value)->toBe('warn')
            ->and(array_map(fn ($finding) => $finding->subject, $result->findings))->toBe(['opcache-hit-rate', 'opcache-memory'])
            ->and($result->findings[0]->severity->value)->toBe('low')
            ->and($result->findings[0]->message)->toContain('85.25 %', 'web SAPI', '12 minutes old')
            ->and($result->findings[1]->message)->toContain('92.5 %')
            ->and($result->findings[0]->details)->toMatchArray(['sapi' => 'web', 'ageMinutes' => 12, 'hitRate' => 85.25, 'memoryUsedPercent' => 92.5, 'cachedScripts' => 1500]);
    });

    it('passes on a healthy web snapshot even when the CLI has no OPcache', function () {
        recordWebRuntime([]);

        expect(phpRuntimeResult(new PhpOpcacheCheck, ['isOpcacheEnabled' => false])->status->value)->toBe('pass');
    });

    it('is skipped on a hand-built posture without the PHP runtime', function () {
        $snapshot = (new ProjectSnapshotData(null, null, [], [], null))->withLaravelPosture(new LaravelPostureData(
            environment: 'production',
            isDebugModeEnabled: false,
            isConfigurationCached: true,
            areRoutesCached: true,
            areEventsCached: true,
            isMaintenanceModeActive: false,
            pendingMigrations: [],
            failedJobCount: 0,
            oldestFailedJobAt: null,
            isSchedulerHeartbeatReadable: true,
            schedulerHeartbeatAt: null,
            collectedAt: new DateTimeImmutable,
        ));

        expect((new PhpOpcacheCheck)->run($snapshot, ExcubiaConfigurationData::defaults())->status->value)->toBe('skipped')
            ->and((new PhpProductionIniCheck)->run($snapshot, ExcubiaConfigurationData::defaults())->status->value)->toBe('skipped');
    });
});

describe('php-production-ini', function () {
    it('is skipped without a posture or outside production', function () {
        $standalone = phpRuntimeResult(new PhpProductionIniCheck, isBooted: false);
        $local = phpRuntimeResult(new PhpProductionIniCheck, ['isDisplayErrorsEnabled' => true], 'local');

        expect($standalone->findings[0]->message)->toContain('requires Artisan')
            ->and($local->status->value)->toBe('skipped')
            ->and($local->findings[0]->message)->toContain('production environment only');
    });

    it('fails at high for display_errors and warns at low for expose_php and log_errors', function () {
        $result = phpRuntimeResult(new PhpProductionIniCheck, ['isDisplayErrorsEnabled' => true, 'isExposePhpEnabled' => true, 'isLogErrorsEnabled' => false]);

        expect($result->status->value)->toBe('fail')
            ->and(array_map(fn ($finding) => [$finding->subject, $finding->severity->value, $finding->status->value], $result->findings))->toBe([
                ['display_errors', 'high', 'fail'],
                ['expose_php', 'low', 'warn'],
                ['log_errors', 'low', 'warn'],
            ])
            ->and($result->findings[0]->message)->toContain('CLI SAPI')
            ->and($result->findings[0]->details)->toBe(['directive' => 'display_errors', 'isEnabled' => true, 'sapi' => 'cli', 'ageMinutes' => null])
            ->and($result->findings[2]->details)->toBe(['directive' => 'log_errors', 'isEnabled' => false, 'sapi' => 'cli', 'ageMinutes' => null]);
    });

    it('passes a production-ready php.ini and prefers the web snapshot', function () {
        expect(phpRuntimeResult(new PhpProductionIniCheck)->status->value)->toBe('pass');

        recordWebRuntime(['isDisplayErrorsEnabled' => true]);
        $result = phpRuntimeResult(new PhpProductionIniCheck);

        expect($result->findings)->toHaveCount(1)
            ->and($result->findings[0]->details)->toMatchArray(['sapi' => 'web', 'ageMinutes' => 5]);
    });

    it('keeps ini file paths and values out of the report', function () {
        app()->setBasePath(temporaryProject(['composer.json' => '{}', 'composer.lock' => '{"packages": [], "packages-dev": []}']));
        app()['env'] = 'production';
        recordWebRuntime(['isDisplayErrorsEnabled' => true, 'isOpcacheEnabled' => false]);

        Artisan::call('excubia:check', ['--format' => 'json', '--no-banner' => true, '--fail-on' => 'none', '--only' => 'php-opcache,php-production-ini']);
        $web = Artisan::output();
        app('cache')->forget(PhpWebRuntimeSnapshotService::CACHE_KEY);
        Artisan::call('excubia:check', ['--format' => 'json', '--no-banner' => true, '--fail-on' => 'none', '--only' => 'php-opcache,php-production-ini']);
        $cli = Artisan::output();
        $iniFile = php_ini_loaded_file();

        expect($web)->toContain('"sapi": "web"', '"directive": "display_errors"')
            ->and($cli)->toContain('"checkId": "php-opcache"')
            ->and($web.$cli)->not->toContain(dirname(PHP_BINARY));

        if (is_string($iniFile)) {
            expect($web.$cli)->not->toContain($iniFile);
        }
    });
});

describe('web runtime snapshot', function () {
    it('writes at most once an hour', function () {
        $first = recordWebRuntime(['hitRate' => 50.0], 30);
        PhpWebRuntimeSnapshotService::record(app(), time());

        expect($first->reads)->toBe(1)
            ->and(PhpWebRuntimeSnapshotService::read(app())['hitRate'] ?? null)->toBe(50.0);

        PhpWebRuntimeSnapshotService::record(app(), time() + 3600);

        expect($first->reads)->toBe(2);
    });

    it('ignores a malformed snapshot and survives a broken cache', function () {
        app('cache')->put(PhpWebRuntimeSnapshotService::CACHE_KEY, ['collectedAt' => time(), 'isOpcacheEnabled' => 'yes'], 60);

        expect(PhpWebRuntimeSnapshotService::read(app()))->toBeNull();

        app('cache')->put(PhpWebRuntimeSnapshotService::CACHE_KEY, 'not an array', 60);

        expect(PhpWebRuntimeSnapshotService::read(app()))->toBeNull();

        config(['cache.default' => 'missing-store']);
        app()->forgetInstance('cache');
        app()->forgetInstance('cache.store');
        PhpWebRuntimeSnapshotService::record(app(), time());

        expect(PhpWebRuntimeSnapshotService::read(app()))->toBeNull();
    });

    it('reads the real runtime when nothing is bound', function () {
        PhpWebRuntimeSnapshotService::record(app(), time());
        $snapshot = PhpWebRuntimeSnapshotService::read(app());

        expect($snapshot)->toBeArray()
            ->and($snapshot['isDisplayErrorsEnabled'] ?? null)->toBe(PhpRuntimeService::isOn(ini_get('display_errors')));
    });
});

describe('PhpRuntimeService', function () {
    it('reads booleans, percentages and a count only', function () {
        $runtime = (new PhpRuntimeService)->read();

        expect(array_keys($runtime))->toBe(['isOpcacheEnabled', 'hitRate', 'memoryUsedPercent', 'cachedScripts', 'isValidateTimestampsEnabled', 'isDisplayErrorsEnabled', 'isExposePhpEnabled', 'isLogErrorsEnabled'])
            ->and($runtime['isOpcacheEnabled'])->toBeBool()
            ->and($runtime['isLogErrorsEnabled'])->toBe(PhpRuntimeService::isOn(ini_get('log_errors')));
    });

    it('reads every on value of an ini directive', function (string|false $value, bool $isOn) {
        expect(PhpRuntimeService::isOn($value))->toBe($isOn);
    })->with([
        ['1', true], ['On', true], ['stderr', true], ['STDOUT', true], ['yes', true], ['true', true],
        ['0', false], ['Off', false], ['', false], [false, false],
    ]);

    it('computes the hit rate and the used memory', function () {
        expect(PhpRuntimeService::hitRate(['hits' => 900, 'misses' => 100]))->toBe(90.0)
            ->and(PhpRuntimeService::hitRate(['hits' => 0, 'misses' => 0]))->toBeNull()
            ->and(PhpRuntimeService::hitRate([]))->toBeNull()
            ->and(PhpRuntimeService::memoryUsedPercent(['used_memory' => 60, 'free_memory' => 30, 'wasted_memory' => 10]))->toBe(70.0)
            ->and(PhpRuntimeService::memoryUsedPercent(['used_memory' => 0, 'free_memory' => 0, 'wasted_memory' => 0]))->toBeNull()
            ->and(PhpRuntimeService::memoryUsedPercent([]))->toBeNull();
    });
});
