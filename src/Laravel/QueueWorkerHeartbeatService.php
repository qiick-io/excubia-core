<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Worker;
use Throwable;

/**
 * Queue worker heartbeat for `laravel-queue-workers` (#151). The service
 * provider always registers it, like the scheduler heartbeat. The `Looping`
 * listener is added once a queue worker is built (`queue:work`,
 * `horizon:work`), so web requests and other commands carry no listener at
 * all. On the worker's `Looping` event (before every job and every idle poll) it stores
 * the Unix time under `excubia:worker-heartbeat:<connection>:<queue>` in
 * the default cache store, one key per queue the worker reads.
 *
 * The write is throttled to once a minute per connection and queue with an
 * in-memory timestamp, so a busy worker costs one array lookup per loop and
 * one cache write a minute. Every error is swallowed: a heartbeat must never
 * stop or slow down a worker.
 */
final class QueueWorkerHeartbeatService
{
    /**
     * Prefix of the heartbeat cache keys, followed by `<connection>:<queue>`.
     */
    public const CACHE_KEY_PREFIX = 'excubia:worker-heartbeat:';

    /**
     * At most one write per connection and queue in this many seconds.
     */
    public const THROTTLE_SECONDS = 60;

    /**
     * Last write per `<connection>:<queue>` in this process, as a Unix time.
     *
     * @var array<string, int>
     */
    private static array $lastWrittenAt = [];

    /**
     * Listens to `Looping` on the app's event dispatcher once the app builds
     * its first queue worker.
     */
    public static function register(Application $application): void
    {
        $isListening = false;

        $application->afterResolving(Worker::class, static function () use ($application, &$isListening): void {
            if ($isListening) {
                return;
            }

            $isListening = true;
            $application->make('events')->listen(Looping::class, static function (Looping $event) use ($application): void {
                self::record($application, $event->connectionName, $event->queue, time());
            });
        });
    }

    /**
     * Writes the heartbeat of each queue in `$queue` (a comma-separated
     * worker queue list) unless it was written less than a minute ago.
     */
    public static function record(Application $application, string $connectionName, string $queue, int $now): void
    {
        try {
            foreach (explode(',', $queue) as $queueName) {
                $key = self::cacheKey($connectionName, $queueName);

                if (isset(self::$lastWrittenAt[$key]) && $now - self::$lastWrittenAt[$key] < self::THROTTLE_SECONDS) {
                    continue;
                }

                self::$lastWrittenAt[$key] = $now;
                $application->make('cache')->forever($key, $now);
            }
        } catch (Throwable) {
            // The heartbeat is best effort: the check skips or warns instead.
        }
    }

    public static function cacheKey(string $connectionName, string $queueName): string
    {
        return self::CACHE_KEY_PREFIX.$connectionName.':'.$queueName;
    }

    /**
     * Forgets the in-memory throttle, for tests.
     */
    public static function reset(): void
    {
        self::$lastWrittenAt = [];
    }
}
