<?php

declare(strict_types=1);

namespace Qiick\Excubia\Contracts;

/**
 * Reads the load, CPU and memory of the machine for the `server` runtime
 * type (#149). Tests fake it; `ServerResourcesService` is the real one.
 * Each reader answers null when the platform does not expose the value.
 */
interface ServerResourcesContract
{
    /**
     * Load average over 1, 5 and 15 minutes, or null.
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    public function loadAverages(): ?array;

    /**
     * Logical CPU cores, or null.
     */
    public function cpuCoreCount(): ?int;

    /**
     * Used and total memory in bytes, or null. Inside a container with a
     * memory limit, the container's (#173).
     *
     * @return array{usedBytes: int, totalBytes: int}|null
     */
    public function memory(): ?array;

    /**
     * Effective CPU cores from a container CPU quota (quota / period, may
     * be fractional such as 1.5), or null without a quota (#173).
     */
    public function cpuLimit(): ?float;

    /**
     * Whether a container (cgroup) memory or CPU limit applies (#173).
     */
    public function isContainer(): bool;
}
