<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Registers `php artisan excubia:check` and `excubia:push` when this package is installed in
 * a Laravel app, and the opt-in health endpoint. The provider does not boot the scanned project.
 *
 * The endpoint route exists only when `excubia.json` has `healthEndpoint.enabled: true` and
 * `EXCUBIA_HEALTH_SECRET` is set; enabled without a secret, the route is not registered.
 *
 * ponytail: without `route:cache` this reads `excubia.json` once per boot (one small file);
 * with `route:cache` the route is baked into the cache when it is built.
 */
final class ExcubiaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([
            ExcubiaCheckCommand::class,
            ExcubiaPushCommand::class,
        ]);

        $this->registerHealthEndpoint();
    }

    private function registerHealthEndpoint(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $configuration = HealthEndpointService::enabledConfiguration($this->app, null);

        if ($configuration === null || HealthEndpointService::secret() === null) {
            return;
        }

        $this->app->make('router')
            ->get($configuration->healthEndpointPath, HealthEndpointService::class)
            ->name('excubia.health');
    }
}
