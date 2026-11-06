<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Contracts\DiskSpaceContract;
use Qiick\Excubia\Contracts\ServerResourcesContract;
use Qiick\Excubia\Enums\RuntimeTypeEnum;

/**
 * One `server` runtime aggregate (#149): load averages, CPU cores, memory
 * and the disk that holds `storage/`, taken once per `excubia:push-runtime`
 * run. A value the platform does not expose is null. Inside a container
 * with cgroup limits (#173), memory is the container's, `cpuLimit` holds
 * the CPU quota in cores and `isContainer` is true.
 *
 * The server is identified by `runtime.server.name`, else by a stable,
 * non-reversible id (`serverIdentity()`); the hostname and the measured
 * directory never leave this class.
 */
final class ServerMetricsService
{
    /**
     * Hex characters of the hashed server id.
     */
    public const SERVER_ID_LENGTH = 12;

    private const MEBIBYTE = 1048576;

    private const GIBIBYTE = 1073741824;

    public function __construct(
        private readonly ServerResourcesContract $resources = new ServerResourcesService,
        private readonly DiskSpaceContract $diskSpace = new DiskSpaceService,
    ) {}

    /**
     * The aggregate for the minute that holds `$timestamp`. `count` is 1;
     * two samples of one server in one minute merge with the larger value
     * of each gauge (RuntimeBufferService::GAUGE_FIELDS).
     *
     * @return array<string, mixed>
     */
    public function aggregate(string $server, string $diskDirectory, float $sampleRate, int $timestamp): array
    {
        $loadAverages = $this->resources->loadAverages();
        $memory = $this->resources->memory();
        $disk = $this->diskSpace->usage($diskDirectory);

        return [
            'type' => RuntimeTypeEnum::Server->value,
            'bucket' => gmdate('Y-m-d\TH:i:00\Z', $timestamp),
            'sampleRate' => $sampleRate,
            'server' => $server,
            'count' => 1,
            'errorCount' => 0,
            'loadAverage1' => $loadAverages === null ? null : round($loadAverages[0], 2),
            'loadAverage5' => $loadAverages === null ? null : round($loadAverages[1], 2),
            'loadAverage15' => $loadAverages === null ? null : round($loadAverages[2], 2),
            'cpuCores' => $this->resources->cpuCoreCount(),
            'cpuLimit' => $this->resources->cpuLimit(),
            'memoryUsedMb' => $memory === null ? null : intdiv($memory['usedBytes'], self::MEBIBYTE),
            'memoryTotalMb' => $memory === null ? null : intdiv($memory['totalBytes'], self::MEBIBYTE),
            'diskUsedGb' => $disk === null ? null : round($disk['usedBytes'] / self::GIBIBYTE, 2),
            'diskTotalGb' => $disk === null ? null : round($disk['totalBytes'] / self::GIBIBYTE, 2),
            'isContainer' => $this->resources->isContainer(),
        ];
    }

    /**
     * `runtime.server.name` when set, else the first 12 hex characters of
     * sha256(hostname + sha256(app key)): stable per machine and app, and
     * not reversible to the hostname without the app key.
     */
    public static function serverIdentity(?string $configuredName, string $hostname, string $applicationKey): string
    {
        if ($configuredName !== null) {
            return $configuredName;
        }

        return substr(hash('sha256', $hostname.hash('sha256', $applicationKey)), 0, self::SERVER_ID_LENGTH);
    }
}
