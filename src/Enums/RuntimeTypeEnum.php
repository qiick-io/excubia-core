<?php

declare(strict_types=1);

namespace Qiick\Excubia\Enums;

/**
 * The kinds of runtime aggregate the Laravel collector records
 * (`docs/runtime-schema.json`). Each one is a separate opt-in under the
 * `runtime` key of `excubia.json`; the value is the `type` of an aggregate.
 *
 * The five types of 1.0.0-beta.1 are on by default once `runtime.enabled`
 * is true. The types added in 1.0.0-beta.2 (#132, #133) are off by default,
 * so an excubia-app that does not know them yet never receives them.
 */
enum RuntimeTypeEnum: string
{
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
}
