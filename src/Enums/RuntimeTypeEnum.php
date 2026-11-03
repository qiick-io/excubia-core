<?php

declare(strict_types=1);

namespace Qiick\Excubia\Enums;

/**
 * The kinds of runtime aggregate the Laravel collector records
 * (`docs/runtime-schema.json`). Each one is a separate opt-in under the
 * `runtime` key of `excubia.json`; the value is the `type` of an aggregate.
 */
enum RuntimeTypeEnum: string
{
    case Request = 'request';
    case SlowQuery = 'slowQuery';
    case Job = 'job';
    case ScheduledTask = 'scheduledTask';
    case OutgoingHttp = 'outgoingHttp';

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
        };
    }
}
