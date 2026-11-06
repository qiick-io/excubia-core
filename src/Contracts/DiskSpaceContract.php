<?php

declare(strict_types=1);

namespace Qiick\Excubia\Contracts;

/**
 * Measures the disk that holds a directory, for `laravel-disk-space` and the
 * `server` runtime type (#149). Tests fake it; `DiskSpaceService` is the
 * real one.
 */
interface DiskSpaceContract
{
    /**
     * Used share of the disk holding `$directory`, in whole percent (rounded
     * down), or null when it cannot be measured.
     */
    public function usedPercent(string $directory): ?int;

    /**
     * Used and total size of the disk holding `$directory`, in bytes, or
     * null when it cannot be measured.
     *
     * @return array{usedBytes: float, totalBytes: float}|null
     */
    public function usage(string $directory): ?array;
}
