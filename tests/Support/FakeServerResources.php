<?php

declare(strict_types=1);

namespace Qiick\Excubia\Tests\Support;

use Qiick\Excubia\Contracts\ServerResourcesContract;
use RuntimeException;

/**
 * Server reader stand-in with fixed readings, for the `server` runtime type.
 * `$shouldFail` makes every reader throw, as a broken platform would.
 */
final class FakeServerResources implements ServerResourcesContract
{
    public int $readCount = 0;

    /**
     * @param  array{0: float, 1: float, 2: float}|null  $loadAverages
     * @param  array{usedBytes: int, totalBytes: int}|null  $memory
     */
    public function __construct(
        private readonly ?array $loadAverages = [0.523, 0.5, 1.0],
        private readonly ?int $cpuCoreCount = 4,
        private readonly ?array $memory = ['usedBytes' => 2147483648, 'totalBytes' => 8589934592],
        private readonly bool $shouldFail = false,
    ) {}

    public function loadAverages(): ?array
    {
        $this->readCount++;

        if ($this->shouldFail) {
            throw new RuntimeException('Cannot read /proc/loadavg on host-sentinel');
        }

        return $this->loadAverages;
    }

    public function cpuCoreCount(): ?int
    {
        return $this->cpuCoreCount;
    }

    public function memory(): ?array
    {
        return $this->memory;
    }
}
