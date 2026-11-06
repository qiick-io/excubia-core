<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Contracts\PhpRuntimeContract;

/**
 * OPcache status through `opcache_get_status(false)` (no script list) and
 * the production directives through `ini_get()`, for the SAPI that runs
 * it. Only the health endpoint calls it, so it reads the web SAPI; the CLI
 * that runs Artisan has its own ini settings and is never checked.
 *
 * OPcache counts as enabled only when `opcache_get_status()` says so, so
 * on the CLI it follows `opcache.enable_cli`. With `opcache.restrict_api`
 * the status is unreadable and OPcache counts as disabled.
 */
final class PhpRuntimeService implements PhpRuntimeContract
{
    public function read(): array
    {
        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $status = is_array($status) && ($status['opcache_enabled'] ?? false) === true ? $status : null;
        $statistics = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];
        $memory = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
        $cachedScripts = $statistics['num_cached_scripts'] ?? null;

        return [
            'isOpcacheEnabled' => $status !== null,
            'hitRate' => $status === null ? null : self::hitRate($statistics),
            'memoryUsedPercent' => $status === null ? null : self::memoryUsedPercent($memory),
            'cachedScripts' => is_int($cachedScripts) ? $cachedScripts : null,
            'isValidateTimestampsEnabled' => self::isOn(ini_get('opcache.validate_timestamps')),
            'isDisplayErrorsEnabled' => self::isOn(ini_get('display_errors')),
            'isExposePhpEnabled' => self::isOn(ini_get('expose_php')),
            'isLogErrorsEnabled' => self::isOn(ini_get('log_errors')),
        ];
    }

    /**
     * Whether an ini value means "on". `display_errors` also takes `stdout`
     * and `stderr`, which both display errors.
     */
    public static function isOn(string|false $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'on', 'yes', 'true', 'stdout', 'stderr'], true);
    }

    /**
     * Hits over hits plus misses, in percent with two decimals; null before
     * the first lookup.
     *
     * @param  array<array-key, mixed>  $statistics  the `opcache_statistics` section
     */
    public static function hitRate(array $statistics): ?float
    {
        $hits = $statistics['hits'] ?? null;
        $misses = $statistics['misses'] ?? null;

        if (! is_int($hits) || ! is_int($misses) || $hits + $misses === 0) {
            return null;
        }

        return round($hits / ($hits + $misses) * 100, 2);
    }

    /**
     * Used plus wasted shared memory over the whole OPcache memory, in
     * percent with two decimals: wasted memory is only freed by a restart.
     *
     * @param  array<array-key, mixed>  $memory  the `memory_usage` section
     */
    public static function memoryUsedPercent(array $memory): ?float
    {
        $used = $memory['used_memory'] ?? null;
        $free = $memory['free_memory'] ?? null;
        $wasted = $memory['wasted_memory'] ?? null;

        if (! is_int($used) || ! is_int($free) || ! is_int($wasted) || $used + $free + $wasted === 0) {
            return null;
        }

        return round(($used + $wasted) / ($used + $free + $wasted) * 100, 2);
    }
}
