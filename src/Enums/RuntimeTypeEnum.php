<?php

declare(strict_types=1);

namespace Qiick\Excubia\Enums;

/**
 * The kinds of runtime aggregate the Laravel collector records
 * (`docs/runtime-schema.json`). Each one is a separate opt-in under the
 * `runtime` key of `excubia.json`; the value is the `type` of an aggregate.
 *
 * The five types of 1.0.0-beta.1 are on by default once `runtime.enabled`
 * is true. The types added in 1.0.0-beta.2 (#132, #133) and later
 * (`duplicateQuery`, #148, `server`, #149, `command`, #152, `health`, #188,
 * `ai` and `aiTool`, #198, `octane`, #213) are off by default, so an
 * excubia-app that does not know them yet never receives them. `ai` and
 * `aiTool` also need the Laravel AI SDK (`laravel/ai`) installed, and
 * `octane` needs Laravel Octane (`laravel/octane`); without them they record
 * nothing.
 *
 * `server` is not an event type: `excubia:push-runtime` takes one sample
 * of the machine per run (ServerMetricsService). Neither is `health` (#188):
 * each run, at most once a minute, runs the fast local Artisan checks and
 * appends one aggregate per check (HealthSampleService).
 *
 * The duration types (`request`, `slowQuery`, `job`, `scheduledTask`,
 * `outgoingHttp`, `queueWait`, `command`, `ai`, `aiTool`) also carry a latency `histogram` (#147) with
 * the bucket bounds below, a public contract (`docs/CONTRACTS.md`).
 */
enum RuntimeTypeEnum: string
{
    /**
     * Upper bounds, in milliseconds and inclusive, of the latency histogram
     * buckets (#147). The histogram has one more bucket for durations above
     * the last bound, so it holds `count(HISTOGRAM_BOUNDS_MS) + 1` counts.
     * Fixed and the same for every type, so histograms always add up
     * element by element; changing them is a contract change.
     */
    public const HISTOGRAM_BOUNDS_MS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000];

    case Request = 'request';
    case SlowQuery = 'slowQuery';
    case Job = 'job';
    case ScheduledTask = 'scheduledTask';
    case OutgoingHttp = 'outgoingHttp';
    case RequestMemory = 'requestMemory';
    case JobMemory = 'jobMemory';
    case QueueWait = 'queueWait';
    case Cache = 'cache';
    case Mail = 'mail';
    case Notification = 'notification';
    case LogLevel = 'logLevel';
    case Deprecation = 'deprecation';
    case DuplicateQuery = 'duplicateQuery';
    case Server = 'server';
    case Command = 'command';
    case Health = 'health';
    case Ai = 'ai';
    case AiTool = 'aiTool';
    case Octane = 'octane';

    /**
     * The `excubia.json` key under `runtime` that switches this type.
     */
    public function configurationKey(): string
    {
        return match ($this) {
            self::Request => 'requests',
            self::SlowQuery => 'slowQueries',
            self::Job => 'jobs',
            self::ScheduledTask => 'scheduler',
            self::OutgoingHttp => 'outgoingHttp',
            self::RequestMemory => 'requestMemory',
            self::JobMemory => 'jobMemory',
            self::QueueWait => 'queueWait',
            self::Cache => 'cache',
            self::Mail => 'mail',
            self::Notification => 'notifications',
            self::LogLevel => 'logLevels',
            self::Deprecation => 'deprecations',
            self::DuplicateQuery => 'duplicateQueries',
            self::Server => 'server',
            self::Command => 'commands',
            self::Health => 'health',
            self::Ai => 'ai',
            self::AiTool => 'aiTools',
            self::Octane => 'octane',
        };
    }

    /**
     * Whether the type is collected when `runtime.enabled` is true and its
     * own `enabled` key is absent.
     */
    public function isEnabledByDefault(): bool
    {
        return match ($this) {
            self::Request, self::SlowQuery, self::Job, self::ScheduledTask, self::OutgoingHttp => true,
            default => false,
        };
    }

    /**
     * Index of the histogram bucket a duration falls into: the first bucket
     * whose upper bound is at or above it, else the overflow bucket.
     */
    public static function histogramBucket(float $durationMs): int
    {
        foreach (self::HISTOGRAM_BOUNDS_MS as $index => $upperBoundMs) {
            if ($durationMs <= $upperBoundMs) {
                return $index;
            }
        }

        return count(self::HISTOGRAM_BOUNDS_MS);
    }
}
