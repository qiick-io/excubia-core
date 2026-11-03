<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * Registers `php artisan excubia:check`, `excubia:push`, `excubia:heartbeat` and
 * `excubia:push-runtime` when this package is installed in a Laravel app, schedules
 * the heartbeat every minute for the `laravel-scheduler-heartbeat` check, and
 * registers the opt-in health endpoint and the opt-in runtime monitoring. The
 * provider does not boot the scanned project.
 *
 * The endpoint route exists only when `excubia.json` has `healthEndpoint.enabled: true` and
 * `EXCUBIA_HEALTH_SECRET` is set; enabled without a secret, the route is not registered.
 * Runtime listeners and the every-minute `excubia:push-runtime` schedule exist only when
 * `runtime.enabled` is true; otherwise nothing is listened to and nothing runs.
 * An invalid `excubia.json` disables both: booting the app never fails on Excubia config.
 *
 * ponytail: this reads `excubia.json` once per boot (one small file); with
 * `route:cache` the route is baked into the cache when it is built.
 */
final class ExcubiaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([
            ExcubiaCheckCommand::class,
            ExcubiaPushCommand::class,
            ExcubiaHeartbeatCommand::class,
            ExcubiaPushRuntimeCommand::class,
        ]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('excubia:heartbeat')->everyMinute();
        });

        $configuration = $this->configuration();

        if ($configuration === null) {
            return;
        }

        $this->registerHealthEndpoint($configuration);
        $this->registerRuntimeMonitoring($configuration);
    }

    private function configuration(): ?ExcubiaConfigurationData
    {
        try {
            return (new ExcubiaConfigurationLoader)->load(null, $this->app->basePath());
        } catch (InvalidConfigurationException) {
            return null;
        }
    }

    private function registerHealthEndpoint(ExcubiaConfigurationData $configuration): void
    {
        if ($this->app->routesAreCached() || ! $configuration->isHealthEndpointEnabled || HealthEndpointService::secret() === null) {
            return;
        }

        $this->app->make('router')
            ->get($configuration->healthEndpointPath, HealthEndpointService::class)
            ->name('excubia.health');
    }

    private function registerRuntimeMonitoring(ExcubiaConfigurationData $configuration): void
    {
        if (! $configuration->runtime->isEnabled) {
            return;
        }

        RuntimeCollectorService::register($this->app, $configuration->runtime);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('excubia:push-runtime')->everyMinute();
        });
    }
}
