<?php

declare(strict_types=1);

namespace Qiick\Excubia\Tests\Support;

use Qiick\Excubia\Contracts\DiskSpaceContract;

/**
 * Disk stand-in for the `server` runtime type: fixed usage, and the
 * directory it was asked about.
 */
final class FakeServerDisk implements DiskSpaceContract
{
    public ?string $measuredDirectory = null;

    /**
     * @param  array{usedBytes: float, totalBytes: float}|null  $usage
     */
    public function __construct(private readonly ?array $usage = ['usedBytes' => 32212254720.0, 'totalBytes' => 85899345920.0]) {}

    public function usedPercent(string $directory): ?int
    {
        $usage = $this->usage($directory);

        return $usage === null ? null : (int) floor($usage['usedBytes'] / $usage['totalBytes'] * 100);
    }

    public function usage(string $directory): ?array
    {
        $this->measuredDirectory = $directory;

        return $this->usage;
    }
}
