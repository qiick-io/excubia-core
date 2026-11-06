<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Contracts\ServerResourcesContract;

/**
 * Load, CPU cores and memory read with PHP alone, never through a shell.
 *
 * - Linux: `/proc/loadavg`, `/proc/cpuinfo` and `/proc/meminfo`.
 * - Elsewhere (macOS, BSD): the load average from `sys_getloadavg()`; CPU
 *   cores and memory are null, since only `sysctl` or `vm_stat` expose them
 *   there and Excubia does not start processes.
 *
 * ponytail: macOS cores and memory need `sysctl hw.ncpu hw.memsize` and
 * `vm_stat`; read them through `symfony/process` if it ever becomes a
 * dependency of this package. Production servers are Linux.
 *
 * ponytail: the values are what `/proc` shows, inside a container usually
 * the host's; cgroup limits are not read.
 */
final class ServerResourcesService implements ServerResourcesContract
{
    private const KIBIBYTE = 1024;

    /**
     * @param  string  $procDirectory  `/proc`; tests point it at fixtures
     * @param  string  $operatingSystemFamily  `PHP_OS_FAMILY`; tests pass another one
     */
    public function __construct(
        private readonly string $procDirectory = '/proc',
        private readonly string $operatingSystemFamily = PHP_OS_FAMILY,
    ) {}

    public function loadAverages(): ?array
    {
        if ($this->isLinux()) {
            $contents = $this->procFile('loadavg');

            if ($contents !== null) {
                return self::parseLoadAverages($contents);
            }
        }

        $loadAverages = sys_getloadavg();

        if (! is_array($loadAverages)) {
            return null;
        }

        return [(float) $loadAverages[0], (float) $loadAverages[1], (float) $loadAverages[2]];
    }

    public function cpuCoreCount(): ?int
    {
        $contents = $this->isLinux() ? $this->procFile('cpuinfo') : null;

        return $contents === null ? null : self::parseCpuCoreCount($contents);
    }

    public function memory(): ?array
    {
        $contents = $this->isLinux() ? $this->procFile('meminfo') : null;

        return $contents === null ? null : self::parseMemory($contents);
    }

    /**
     * The first three fields of `/proc/loadavg` (`0.52 0.58 0.59 1/389 12345`).
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    public static function parseLoadAverages(string $contents): ?array
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)/', $contents, $matches) !== 1) {
            return null;
        }

        return [(float) $matches[1], (float) $matches[2], (float) $matches[3]];
    }

    /**
     * The `processor` entries of `/proc/cpuinfo`, one per logical core.
     */
    public static function parseCpuCoreCount(string $contents): ?int
    {
        $count = (int) preg_match_all('/^processor\s*:/m', $contents);

        return $count > 0 ? $count : null;
    }

    /**
     * Used and total memory from `/proc/meminfo` (values in KiB). Used is
     * `MemTotal - MemAvailable`, as `free` reports it; kernels before 3.14
     * have no `MemAvailable`, so it falls back to free, buffers and page
     * cache.
     *
     * @return array{usedBytes: int, totalBytes: int}|null
     */
    public static function parseMemory(string $contents): ?array
    {
        preg_match_all('/^(\w+):\s+(\d+)\s*kB\s*$/m', $contents, $matches);
        $fields = array_combine($matches[1], array_map('intval', $matches[2]));
        $total = $fields['MemTotal'] ?? 0;

        if ($total <= 0) {
            return null;
        }

        $available = $fields['MemAvailable']
            ?? (isset($fields['MemFree']) ? $fields['MemFree'] + ($fields['Buffers'] ?? 0) + ($fields['Cached'] ?? 0) : null);

        if ($available === null) {
            return null;
        }

        return [
            'usedBytes' => max($total - $available, 0) * self::KIBIBYTE,
            'totalBytes' => $total * self::KIBIBYTE,
        ];
    }

    private function isLinux(): bool
    {
        return $this->operatingSystemFamily === 'Linux';
    }

    private function procFile(string $name): ?string
    {
        $path = $this->procDirectory.DIRECTORY_SEPARATOR.$name;
        $contents = is_readable($path) ? @file_get_contents($path) : false;

        return is_string($contents) && $contents !== '' ? $contents : null;
    }
}
