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
 * `bufferMaxBytes` caps the local buffer under `storage/excubia/runtime/`;
 * the oldest entries are dropped first.
 */
final readonly class RuntimeConfigurationData
{
    public const DEFAULT_SLOW_QUERY_THRESHOLD_MS = 1000;

    public const DEFAULT_BUFFER_MAX_BYTES = 1048576;

    public const MINIMUM_BUFFER_MAX_BYTES = 4096;

    /**
     * @param  array<string, bool>  $enabledTypes  keyed by RuntimeTypeEnum value; a missing type is enabled
     * @param  array<string, float>  $sampleRates  keyed by RuntimeTypeEnum value; a missing type records every event
     *
     * @throws InvalidConfigurationException
     */
    public function __construct(
        public bool $isEnabled = false,
        public array $enabledTypes = [],
        public array $sampleRates = [],
        public int $slowQueryThresholdMs = self::DEFAULT_SLOW_QUERY_THRESHOLD_MS,
        public int $bufferMaxBytes = self::DEFAULT_BUFFER_MAX_BYTES,
    ) {
        if ($this->slowQueryThresholdMs < 0) {
            throw new InvalidConfigurationException('runtime.slowQueries.thresholdMs must be zero or greater.');
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
     * True when runtime monitoring is on and this type is not switched off.
     */
    public function isTypeEnabled(RuntimeTypeEnum $type): bool
    {
        return $this->isEnabled && ($this->enabledTypes[$type->value] ?? true);
    }

    public function sampleRate(RuntimeTypeEnum $type): float
    {
        return $this->sampleRates[$type->value] ?? 1.0;
    }
}
