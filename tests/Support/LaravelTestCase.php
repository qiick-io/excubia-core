<?php

declare(strict_types=1);

namespace Qiick\Excubia\Tests\Support;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Qiick\Excubia\Laravel\ExcubiaServiceProvider;

/**
 * Boots a Testbench Laravel app with the Excubia provider for `tests/Laravel`.
 * CI runs that directory on Laravel 12 and 13 (`laravel` job in ci.yml).
 */
abstract class LaravelTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ExcubiaServiceProvider::class];
    }
}
