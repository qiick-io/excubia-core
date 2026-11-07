<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * The `runtime` key of `excubia.json`: opt-in runtime aggregates collected
 * by the Laravel provider and sent with `excubia:push-runtime`.
 *
 * Off by default. When `isEnabled` is false the provider registers no
 * listener and no schedule. Each type has its own switch and sample rate
 * (0 to 1, the share of events recorded, as in Laravel Pulse); every
 * aggregate carries its rate so the app can scale counts by `1 / sampleRate`.
 * `duplicateQueryThreshold` is how many times one query shape must run
 * within one request or job before `duplicateQuery` records it (#148).
 * `ignoredCommands` are the Artisan command names, `*` wildcards allowed,
 * that `command` never records (#152); Excubia's own `excubia:*` commands
 * are never recorded whatever the list holds.
 * `serverName` names this machine in `server` aggregates: the
 * `EXCUBIA_SERVER_NAME` environment variable (#162), else
 * `runtime.server.name` (#149); without it the server is a hashed id, never
 * the hostname. `isServerNameVariableInvalid` is true when the variable is
 * set but does not match SERVER_NAME_PATTERN: the server is then the hashed
 * id, and `excubia:push-runtime` warns.
 * `healthCheckIds` are the checks the `health` type runs (#188), a subset of
 * HEALTH_CHECK_IDS, which only probe local services and never call a data
 * source. A check starts only while `healthCheckBudgetMs` is still left of
 * `healthTotalBudgetMs`, counted from the start of the push-runtime run.
 * `bufferMaxBytes` caps the local buffer under `storage/excubia/runtime/`;
 * the oldest entries are dropped first.
 */
final readonly class RuntimeConfigurationData
{
    public const DEFAULT_SLOW_QUERY_THRESHOLD_MS = 1000;

    /**
     * Executions of one query shape within one request or job from which
     * `duplicateQuery` records it (#148).
     */
    public const DEFAULT_DUPLICATE_QUERY_THRESHOLD = 5;

    public const MINIMUM_DUPLICATE_QUERY_THRESHOLD = 2;

    /**
     * Long-running or interactive commands that `command` skips by default
     * (#152): their duration says nothing, and the scheduler and queue have
     * their own types.
     */
    public const DEFAULT_IGNORED_COMMANDS = ['schedule:run', 'schedule:work', 'queue:work', 'queue:listen', 'horizon*', 'tinker'];

    /**
     * A readable server name: letters, digits, `.`, `_` and `-`, at most 64.
     */
    public const SERVER_NAME_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    /**
     * Environment variable that names this server; it wins over
     * `runtime.server.name` (#162).
     */
    public const SERVER_NAME_VARIABLE = 'EXCUBIA_SERVER_NAME';

    /**
     * The checks the `health` type may run (#188), in run order, and its
     * default list: the fast Artisan checks that only probe local services
     * (database, cache, disks, queue, scheduler heartbeat, Redis) and never
     * call a data source.
     *
     * ponytail: other posture checks (failed jobs, migrations, backups) are
     * not allowed yet; add them here, with their probes gated in
     * LaravelPostureService::collect(), when minute-level alerts need them.
     */
    public const HEALTH_CHECK_IDS = [
        'laravel-database',
        'laravel-cache',
        'laravel-storage',
        'laravel-disk-space',
        'laravel-queue-backlog',
        'laravel-queue-workers',
        'laravel-scheduler-heartbeat',
        'laravel-redis-memory',
        'laravel-maintenance-mode',
    ];

    /**
     * Time reserved for one health check (#188): the database probe alone
     * may wait 3 s for a connect timeout.
     */
    public const DEFAULT_HEALTH_CHECK_BUDGET_MS = 3000;

    /**
     * The push-runtime run time after which no further health check starts.
     */
    public const DEFAULT_HEALTH_TOTAL_BUDGET_MS = 15000;

    public const DEFAULT_BUFFER_MAX_BYTES = 1048576;

    public const MINIMUM_BUFFER_MAX_BYTES = 4096;

    /**
     * @param  array<string, bool>  $enabledTypes  keyed by RuntimeTypeEnum value; a missing type takes its default
     * @param  array<string, float>  $sampleRates  keyed by RuntimeTypeEnum value; a missing type records every event
     * @param  list<string>  $ignoredCommands  command names or `*` patterns that `command` skips
     * @param  list<string>  $healthCheckIds  check ids of HEALTH_CHECK_IDS that `health` runs
     *
     * @throws InvalidConfigurationException
     */
    public function __construct(
        public bool $isEnabled = false,
        public array $enabledTypes = [],
        public array $sampleRates = [],
        public int $slowQueryThresholdMs = self::DEFAULT_SLOW_QUERY_THRESHOLD_MS,
        public int $bufferMaxBytes = self::DEFAULT_BUFFER_MAX_BYTES,
        public int $duplicateQueryThreshold = self::DEFAULT_DUPLICATE_QUERY_THRESHOLD,
        public array $ignoredCommands = self::DEFAULT_IGNORED_COMMANDS,
        public ?string $serverName = null,
        public bool $isServerNameVariableInvalid = false,
        public array $healthCheckIds = self::HEALTH_CHECK_IDS,
        public int $healthCheckBudgetMs = self::DEFAULT_HEALTH_CHECK_BUDGET_MS,
        public int $healthTotalBudgetMs = self::DEFAULT_HEALTH_TOTAL_BUDGET_MS,
    ) {
        foreach ($this->healthCheckIds as $index => $checkId) {
            if (! in_array($checkId, self::HEALTH_CHECK_IDS, true)) {
                throw new InvalidConfigurationException('runtime.health.checks takes only '.implode(', ', self::HEALTH_CHECK_IDS).'; ['.$checkId.'] is not one of them.');
            }

            if (array_search($checkId, $this->healthCheckIds, true) !== $index) {
                throw new InvalidConfigurationException('runtime.health.checks lists ['.$checkId.'] more than once.');
            }
        }

        if ($this->healthCheckBudgetMs < 1 || $this->healthTotalBudgetMs < $this->healthCheckBudgetMs) {
            throw new InvalidConfigurationException('runtime.health.checkBudgetMs must be at least 1 and no more than runtime.health.totalBudgetMs.');
        }

        if ($this->serverName !== null && preg_match(self::SERVER_NAME_PATTERN, $this->serverName) !== 1) {
            throw new InvalidConfigurationException('runtime.server.name must be 1 to 64 letters, digits, dots, underscores or hyphens.');
        }

        if ($this->slowQueryThresholdMs < 0) {
            throw new InvalidConfigurationException('runtime.slowQueries.thresholdMs must be zero or greater.');
        }

        if ($this->duplicateQueryThreshold < self::MINIMUM_DUPLICATE_QUERY_THRESHOLD) {
            throw new InvalidConfigurationException('runtime.duplicateQueries.threshold must be at least '.self::MINIMUM_DUPLICATE_QUERY_THRESHOLD.'.');
        }

        if ($this->bufferMaxBytes < self::MINIMUM_BUFFER_MAX_BYTES) {
            throw new InvalidConfigurationException('runtime.bufferMaxBytes must be at least '.self::MINIMUM_BUFFER_MAX_BYTES.'.');
        }

        foreach ($this->sampleRates as $type => $sampleRate) {
            if ($sampleRate < 0.0 || $sampleRate > 1.0) {
                throw new InvalidConfigurationException('runtime.'.RuntimeTypeEnum::from($type)->configurationKey().'.sampleRate must be between 0 and 1.');
            }
        }
    }

    /**
     * True when runtime monitoring is on and this type is switched on,
     * explicitly or by default (RuntimeTypeEnum::isEnabledByDefault()).
     */
    public function isTypeEnabled(RuntimeTypeEnum $type): bool
    {
        return $this->isEnabled && ($this->enabledTypes[$type->value] ?? $type->isEnabledByDefault());
    }

    public function sampleRate(RuntimeTypeEnum $type): float
    {
        return $this->sampleRates[$type->value] ?? 1.0;
    }
}
