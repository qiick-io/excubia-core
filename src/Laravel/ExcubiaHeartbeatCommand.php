<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Illuminate\Console\Command;

/**
 * `php artisan excubia:heartbeat`. The service provider schedules it every
 * minute; it only stores the current Unix time under `CACHE_KEY` in the
 * default cache store, so `laravel-scheduler-heartbeat` can tell whether
 * the scheduler still runs. It is the only write the posture checks need.
 */
final class ExcubiaHeartbeatCommand extends Command
{
    public const CACHE_KEY = 'excubia:scheduler-heartbeat';

    protected $signature = 'excubia:heartbeat';

    protected $description = 'Record that the scheduler ran (used by the laravel-scheduler-heartbeat check).';

    public function handle(): int
    {
        $this->getLaravel()->make('cache')->forever(self::CACHE_KEY, time());

        return self::SUCCESS;
    }
}
