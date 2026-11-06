<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Qiick\Excubia\Contracts\PhpRuntimeContract;
use Qiick\Excubia\Services\PhpRuntimeService;
use Throwable;

/**
 * Web-SAPI snapshot of the OPcache status and production directives for
 * `php-opcache` and `php-production-ini` (#172).
 *
 * Artisan runs on the CLI SAPI, whose OPcache (`opcache.enable_cli`) and
 * ini values often differ from PHP-FPM's. When the health endpoint serves
 * an authenticated request it runs under the web SAPI, so it stores what
 * `PhpRuntimeContract` reads there under `excubia:php-runtime:web` in the
 * default cache store, at most once an hour (one cache read per request
 * otherwise). `excubia:check` reads only these values and never the CLI's:
 * without a snapshot both checks are skipped. The snapshot holds booleans,
 * percentages, a count and the Unix time: never a path or an ini value. It
 * expires after a day, so a stale snapshot makes the checks skip again.
 * Every error is swallowed: the endpoint must answer even when the cache
 * is down.
 *
 * ponytail: one key for the whole app, so with several web servers it is
 * whichever server answered last; key it per server if that ever matters.
 */
final class PhpWebRuntimeSnapshotService
{
    public const CACHE_KEY = 'excubia:php-runtime:web';

    /**
     * At most one write in this many seconds.
     */
    public const REFRESH_SECONDS = 3600;

    /**
     * The snapshot expires after this many seconds.
     */
    public const EXPIRE_SECONDS = 86400;

    /**
     * Stores the web values unless the stored snapshot is younger than an hour.
     */
    public static function record(Application $application, int $now): void
    {
        try {
            $cache = $application->make('cache');
            $stored = self::read($application);

            if ($stored !== null && $now - $stored['collectedAt'] < self::REFRESH_SECONDS) {
                return;
            }

            $runtime = $application->bound(PhpRuntimeContract::class) ? $application->make(PhpRuntimeContract::class) : new PhpRuntimeService;
            $cache->put(self::CACHE_KEY, ['collectedAt' => $now, ...$runtime->read()], self::EXPIRE_SECONDS);
        } catch (Throwable) {
            // Best effort: the checks stay skipped until a snapshot is stored.
        }
    }

    /**
     * The stored web snapshot, or null when there is none, it is malformed or
     * the cache cannot be read.
     *
     * @return array{collectedAt: int, isOpcacheEnabled: bool, hitRate: float|null, memoryUsedPercent: float|null, cachedScripts: int|null, isValidateTimestampsEnabled: bool, isDisplayErrorsEnabled: bool, isExposePhpEnabled: bool, isLogErrorsEnabled: bool}|null
     */
    public static function read(Application $application): ?array
    {
        try {
            $snapshot = $application->make('cache')->get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($snapshot) || ! is_int($snapshot['collectedAt'] ?? null)) {
            return null;
        }

        foreach (['isOpcacheEnabled', 'isValidateTimestampsEnabled', 'isDisplayErrorsEnabled', 'isExposePhpEnabled', 'isLogErrorsEnabled'] as $key) {
            if (! is_bool($snapshot[$key] ?? null)) {
                return null;
            }
        }

        $hitRate = $snapshot['hitRate'] ?? null;
        $memoryUsedPercent = $snapshot['memoryUsedPercent'] ?? null;
        $cachedScripts = $snapshot['cachedScripts'] ?? null;

        return [
            'collectedAt' => $snapshot['collectedAt'],
            'isOpcacheEnabled' => $snapshot['isOpcacheEnabled'],
            'hitRate' => is_int($hitRate) || is_float($hitRate) ? (float) $hitRate : null,
            'memoryUsedPercent' => is_int($memoryUsedPercent) || is_float($memoryUsedPercent) ? (float) $memoryUsedPercent : null,
            'cachedScripts' => is_int($cachedScripts) ? $cachedScripts : null,
            'isValidateTimestampsEnabled' => $snapshot['isValidateTimestampsEnabled'],
            'isDisplayErrorsEnabled' => $snapshot['isDisplayErrorsEnabled'],
            'isExposePhpEnabled' => $snapshot['isExposePhpEnabled'],
            'isLogErrorsEnabled' => $snapshot['isLogErrorsEnabled'],
        ];
    }
}
