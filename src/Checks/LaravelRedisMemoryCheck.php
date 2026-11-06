<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\LaravelPostureData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;

/**
 * Reports the memory of the Redis connections the app uses (#170), like
 * spatie/laravel-health's `RedisMemoryUsageCheck`. Artisan only.
 *
 * One row at most per connection (subject the connection name), the first
 * that applies: used memory at `checks.laravel-redis-memory.failAtPercentUsed`
 * (default 90) of `maxmemory` or more fails at high, at `warnAtPercentUsed`
 * (default 80) warns at medium; no `maxmemory` (unbounded) warns at low; the
 * `noeviction` policy on the cache connection warns at low, because a full
 * cache then rejects writes instead of evicting keys. Connections that could
 * not be read are left out (reachability is `laravel-cache`'s job). Names,
 * sizes and the policy only: never a host, port or password.
 */
final class LaravelRedisMemoryCheck implements HealthCheckContract
{
    private const BYTES_PER_MEGABYTE = 1_048_576;

    private const EVIDENCE_URL = 'https://redis.io/docs/latest/develop/reference/eviction/';

    public function id(): string
    {
        return 'laravel-redis-memory';
    }

    public function name(): string
    {
        return 'Redis memory';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $posture = $projectSnapshot->laravelPosture;

        if ($posture === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'redis', LaravelPostureData::REQUIRES_ARTISAN_MESSAGE);
        }

        if ($posture->redisMemory === null || $posture->redisMemory === []) {
            return CheckResultData::skipped($this->id(), $this->name(), 'redis', 'No Redis connection is used by the default cache store, queue connection or session.');
        }

        $findings = [];
        $readCount = 0;

        foreach ($posture->redisMemory as $memory) {
            if ($memory['usedBytes'] === null) {
                continue;
            }

            $readCount++;
            $finding = $this->finding($memory['connection'], $memory['isCacheConnection'], $memory['usedBytes'], $memory['maxBytes'] ?? 0, $memory['policy'], $configuration);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        if ($readCount === 0) {
            return CheckResultData::skipped($this->id(), $this->name(), 'redis', 'No Redis connection answered INFO memory.');
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    private function finding(string $connection, bool $isCacheConnection, int $usedBytes, int $maxBytes, ?string $policy, ExcubiaConfigurationData $configuration): ?FindingData
    {
        $details = [
            'connection' => $connection,
            'usedMb' => round($usedBytes / self::BYTES_PER_MEGABYTE, 2),
            'maxMb' => $maxBytes > 0 ? round($maxBytes / self::BYTES_PER_MEGABYTE, 2) : null,
            'policy' => $policy,
        ];

        if ($maxBytes === 0) {
            return $this->redisFinding(SeverityEnum::Low, $connection, 'Redis connection '.$connection.' has no maxmemory limit, so it can grow until the server runs out of memory.', 'Set maxmemory (and an eviction policy for cache data) in the Redis configuration.', $details);
        }

        $usedPercent = (int) floor($usedBytes * 100 / $maxBytes);

        if ($usedPercent >= $configuration->redisMemoryWarnAtPercentUsed) {
            $isFull = $usedPercent >= $configuration->redisMemoryFailAtPercentUsed;

            return $this->redisFinding($isFull ? SeverityEnum::High : SeverityEnum::Medium, $connection, 'Redis connection '.$connection.' uses '.$usedPercent.' % of its maxmemory.', 'Raise maxmemory, free keys or move data to another Redis instance.', $details);
        }

        if ($isCacheConnection && $policy === 'noeviction') {
            return $this->redisFinding(SeverityEnum::Low, $connection, 'Redis connection '.$connection.' holds the cache with the noeviction policy, so writes fail once maxmemory is reached.', 'Use an evicting policy such as allkeys-lru for cache data, or a separate Redis instance for the cache.', $details);
        }

        return null;
    }

    /**
     * @param  array{connection: string, usedMb: float, maxMb: float|null, policy: string|null}  $details
     */
    private function redisFinding(SeverityEnum $severity, string $connection, string $message, string $fixHint, array $details): FindingData
    {
        return new FindingData(
            checkId: $this->id(),
            severity: $severity,
            status: $severity === SeverityEnum::High ? FindingStatusEnum::Fail : FindingStatusEnum::Warn,
            subject: $connection,
            message: $message,
            fixHint: $fixHint,
            evidenceUrl: self::EVIDENCE_URL,
            details: $details,
        );
    }
}
