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
 * - Containers (#173): inside Docker or Kubernetes `/proc` shows the host.
 *   When the cgroup of the process has a memory limit, memory is the
 *   cgroup's (used minus inactive page cache, out of the limit); when it
 *   has a CPU quota, `cpuLimit()` is quota / period in cores. cgroup v2
 *   (`memory.max`, `memory.current`, `cpu.max`) is tried first, then v1
 *   (`memory/memory.limit_in_bytes`, `memory/memory.usage_in_bytes`,
 *   `cpu/cpu.cfs_quota_us`, `cpu/cpu.cfs_period_us`). `max`, `-1`, a v1
 *   "unlimited" value and a limit at or above the machine's memory are no
 *   limit. `isContainer()` is true when either limit applies.
 *
 * ponytail: only the cgroup mounted at `/sys/fs/cgroup` is read, which is
 * the process's own one inside a container (cgroup namespace). A host
 * process in a limited systemd slice is not seen; resolving
 * `/proc/self/cgroup` would cover it if that is ever needed.
 */
final class ServerResourcesService implements ServerResourcesContract
{
    private const KIBIBYTE = 1024;

    /**
     * cgroup v1 writes a page-aligned `PHP_INT_MAX` for "no limit"; anything
     * at or above 2^60 bytes (1 EiB) is treated as that.
     */
    private const CGROUP_UNLIMITED_BYTES = 1 << 60;

    /**
     * The cgroup readings, read once per instance.
     *
     * @var array{memory: array{usedBytes: int, totalBytes: int}|null, cpuLimit: float|null}|null
     */
    private ?array $cgroupLimits = null;

    /**
     * @param  string  $procDirectory  `/proc`; tests point it at fixtures
     * @param  string  $operatingSystemFamily  `PHP_OS_FAMILY`; tests pass another one
     * @param  string  $cgroupDirectory  `/sys/fs/cgroup`; tests point it at fixtures
     */
    public function __construct(
        private readonly string $procDirectory = '/proc',
        private readonly string $operatingSystemFamily = PHP_OS_FAMILY,
        private readonly string $cgroupDirectory = '/sys/fs/cgroup',
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

        // Through the same parser, so the result is typed whatever PHP's stubs say.
        return is_array($loadAverages) ? self::parseLoadAverages(implode(' ', $loadAverages)) : null;
    }

    public function cpuCoreCount(): ?int
    {
        $contents = $this->isLinux() ? $this->procFile('cpuinfo') : null;

        return $contents === null ? null : self::parseCpuCoreCount($contents);
    }

    public function memory(): ?array
    {
        return $this->cgroupLimits()['memory'] ?? $this->hostMemory();
    }

    public function cpuLimit(): ?float
    {
        return $this->cgroupLimits()['cpuLimit'];
    }

    public function isContainer(): bool
    {
        $limits = $this->cgroupLimits();

        return $limits['memory'] !== null || $limits['cpuLimit'] !== null;
    }

    /**
     * Cores from a CFS quota and period (`cpu.max` holds both, v1 has one
     * file each), 2 decimals; null for no quota (`max`, `-1`) or nonsense.
     */
    public static function parseCpuLimit(string $quota, string $period): ?float
    {
        $quota = trim($quota);
        $period = trim($period);

        if (preg_match('/^\d+$/', $quota) !== 1 || preg_match('/^\d+$/', $period) !== 1 || (int) $quota <= 0 || (int) $period <= 0) {
            return null;
        }

        return round((int) $quota / (int) $period, 2);
    }

    /**
     * Used and total memory of a cgroup: usage minus the inactive page cache
     * (`inactive_file` in v2, `total_inactive_file` in v1 `memory.stat`), as
     * `docker stats` reports it, out of the limit. Null when there is no
     * limit: `max`, v1's unlimited value, or not below `$hostTotalBytes`.
     *
     * @return array{usedBytes: int, totalBytes: int}|null
     */
    public static function parseCgroupMemory(string $limit, string $usage, ?string $statistics, ?int $hostTotalBytes): ?array
    {
        $limit = trim($limit);
        $usage = trim($usage);

        if (preg_match('/^\d+$/', $limit) !== 1 || preg_match('/^\d+$/', $usage) !== 1) {
            return null;
        }

        $totalBytes = (int) $limit;

        if ($totalBytes <= 0 || $totalBytes >= self::CGROUP_UNLIMITED_BYTES || ($hostTotalBytes !== null && $totalBytes >= $hostTotalBytes)) {
            return null;
        }

        $inactiveBytes = 0;

        // v1 has both; its `total_` line counts the child cgroups too.
        if ($statistics !== null && (preg_match('/^total_inactive_file\s+(\d+)\s*$/m', $statistics, $matches) === 1 || preg_match('/^inactive_file\s+(\d+)\s*$/m', $statistics, $matches) === 1)) {
            $inactiveBytes = (int) $matches[1];
        }

        return [
            'usedBytes' => min(max((int) $usage - $inactiveBytes, 0), $totalBytes),
            'totalBytes' => $totalBytes,
        ];
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

    /**
     * @return array{usedBytes: int, totalBytes: int}|null
     */
    private function hostMemory(): ?array
    {
        $contents = $this->isLinux() ? $this->procFile('meminfo') : null;

        return $contents === null ? null : self::parseMemory($contents);
    }

    /**
     * The memory and CPU limits of the cgroup, v2 first, then v1; both null
     * outside Linux or without a cgroup limit.
     *
     * @return array{memory: array{usedBytes: int, totalBytes: int}|null, cpuLimit: float|null}
     */
    private function cgroupLimits(): array
    {
        if ($this->cgroupLimits !== null) {
            return $this->cgroupLimits;
        }

        if (! $this->isLinux()) {
            return $this->cgroupLimits = ['memory' => null, 'cpuLimit' => null];
        }

        $hostTotalBytes = $this->hostMemory()['totalBytes'] ?? null;
        $memory = null;
        $cpuLimit = null;
        $memoryLimit = $this->cgroupFile('memory.max');

        if ($memoryLimit !== null) {
            $memory = self::parseCgroupMemory($memoryLimit, $this->cgroupFile('memory.current') ?? '', $this->cgroupFile('memory.stat'), $hostTotalBytes);
        } else {
            $memoryLimit = $this->cgroupFile('memory/memory.limit_in_bytes');

            if ($memoryLimit !== null) {
                $memory = self::parseCgroupMemory($memoryLimit, $this->cgroupFile('memory/memory.usage_in_bytes') ?? '', $this->cgroupFile('memory/memory.stat'), $hostTotalBytes);
            }
        }

        $cpuMaximum = $this->cgroupFile('cpu.max');

        if ($cpuMaximum !== null) {
            $parts = preg_split('/\s+/', trim($cpuMaximum));
            $cpuLimit = self::parseCpuLimit($parts[0] ?? '', $parts[1] ?? '');
        } else {
            $quota = $this->cgroupFile('cpu/cpu.cfs_quota_us');

            if ($quota !== null) {
                $cpuLimit = self::parseCpuLimit($quota, $this->cgroupFile('cpu/cpu.cfs_period_us') ?? '');
            }
        }

        return $this->cgroupLimits = ['memory' => $memory, 'cpuLimit' => $cpuLimit];
    }

    private function cgroupFile(string $name): ?string
    {
        return self::readFile($this->cgroupDirectory.DIRECTORY_SEPARATOR.$name);
    }

    private function isLinux(): bool
    {
        return $this->operatingSystemFamily === 'Linux';
    }

    private function procFile(string $name): ?string
    {
        return self::readFile($this->procDirectory.DIRECTORY_SEPARATOR.$name);
    }

    private static function readFile(string $path): ?string
    {
        $contents = is_readable($path) ? @file_get_contents($path) : false;

        return is_string($contents) && $contents !== '' ? $contents : null;
    }
}
