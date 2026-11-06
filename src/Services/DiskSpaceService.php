<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Contracts\DiskSpaceContract;

/**
 * Disk usage through `disk_total_space()` and `disk_free_space()`.
 * Read-only; the directory never leaves this class.
 */
final class DiskSpaceService implements DiskSpaceContract
{
    public function usedPercent(string $directory): ?int
    {
        if (! is_dir($directory)) {
            return null;
        }

        $total = @disk_total_space($directory);
        $free = @disk_free_space($directory);

        if (! is_float($total) || ! is_float($free) || $total <= 0) {
            return null;
        }

        return (int) floor(($total - $free) / $total * 100);
    }
}
