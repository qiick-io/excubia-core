<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\HealthReportData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Renderers\OhDearHealthRenderer;
use Qiick\Excubia\Services\HealthResultStoreService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Oh Dear-compatible health endpoint (`docs/health-endpoint.md`).
 *
 * The route action serves the payload the last `excubia:check` /
 * `excubia:push` run stored under `storage/excubia/health.json`. It never
 * runs a check in the request. The `oh-dear-health-check-secret` header is
 * compared in constant time with `EXCUBIA_HEALTH_SECRET`; a wrong or
 * missing secret is a plain 404 so the route stays hidden.
 *
 * ponytail: the secret is read from the process environment like
 * `EXCUBIA_APP_TOKEN`, so with `config:cache` (no `.env` load) it must be
 * set in the real environment; add a published config file if that becomes a need.
 */
final class HealthEndpointService
{
    public const SECRET_VARIABLE = 'EXCUBIA_HEALTH_SECRET';

    public const SECRET_HEADER = 'oh-dear-health-check-secret';

    public function __construct(private readonly Application $application) {}

    public function __invoke(Request $request): Response
    {
        $secret = self::secret();
        $given = $request->header(self::SECRET_HEADER);

        if ($secret === null || ! is_string($given) || ! hash_equals($secret, $given)) {
            throw new NotFoundHttpException;
        }

        $payload = self::store($this->application)->read()
            ?? (new OhDearHealthRenderer(self::now()))->renderNotRunYet();

        return new Response($payload, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * The configuration when the endpoint is enabled in `excubia.json`, else null.
     * An invalid file also gives null: booting the app must never fail on
     * Excubia config; the commands report the error instead.
     */
    public static function enabledConfiguration(Application $application, ?string $configPath): ?ExcubiaConfigurationData
    {
        try {
            $configuration = (new ExcubiaConfigurationLoader)->load($configPath, $application->basePath());
        } catch (InvalidConfigurationException) {
            return null;
        }

        return $configuration->isHealthEndpointEnabled ? $configuration : null;
    }

    /**
     * Run one Artisan alias and, when the endpoint is enabled, store its
     * result. A run that throws or ends without a report (configuration
     * error, missing `composer.lock`) stores the `crashed` payload.
     *
     * @param  Closure((Closure(HealthReportData): void)|null): int  $run  receives the report listener
     *
     * @throws Throwable the run's own exception, after the crash is stored
     */
    public static function recording(Application $application, ?string $configPath, Closure $run): int
    {
        if (self::enabledConfiguration($application, $configPath) === null) {
            return $run(null);
        }

        $store = self::store($application);
        $isRecorded = false;
        $listener = static function (HealthReportData $report) use ($store, &$isRecorded): void {
            $store->write((new OhDearHealthRenderer(self::now()))->render($report));
            $isRecorded = true;
        };

        try {
            $exitCode = $run($listener);
        } catch (Throwable $exception) {
            $store->write((new OhDearHealthRenderer(self::now()))->renderCrashed());

            throw $exception;
        }

        if (! $isRecorded) {
            $store->write((new OhDearHealthRenderer(self::now()))->renderCrashed());
        }

        return $exitCode;
    }

    public static function secret(): ?string
    {
        $value = getenv(self::SECRET_VARIABLE);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function store(Application $application): HealthResultStoreService
    {
        return new HealthResultStoreService($application->storagePath('excubia'.DIRECTORY_SEPARATOR.'health.json'));
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
