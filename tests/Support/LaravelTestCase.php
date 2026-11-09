<?php

declare(strict_types=1);

namespace Qiick\Excubia\Tests\Support;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;
use Qiick\Excubia\Laravel\RuntimeCollectorService;

/**
 * Boots a Testbench Laravel app with the Excubia provider for `tests/Laravel`.
 * CI runs that directory on Laravel 12 and 13 (`laravel` job in ci.yml).
 */
abstract class LaravelTestCase extends TestCase
{
    /**
     * The UTC minute every test's runtime clock starts at
     * (2027-01-15T08:00:00Z, a multiple of 60 seconds).
     */
    public const FROZEN_MINUTE = 1_800_000_000;

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ExcubiaServiceProvider::class];
    }

    /**
     * Freeze the runtime collector's minute (#248): its clock starts at
     * `FROZEN_MINUTE` and moves with real time, so durations stay real, but
     * every event of a test (well under a minute) lands in one bucket and
     * sums never split on a UTC minute boundary. A test that needs the real
     * clock forgets `RuntimeCollectorService::CLOCK_BINDING` before it boots
     * Excubia.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $startedAt = microtime(true);

        $app->instance(RuntimeCollectorService::CLOCK_BINDING, static fn (): float => self::FROZEN_MINUTE + (microtime(true) - $startedAt));
    }
}
