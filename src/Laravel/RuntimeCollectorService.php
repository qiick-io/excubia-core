<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Closure;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Qiick\Excubia\Data\RuntimeConfigurationData;
use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Services\RuntimeBufferService;
use Throwable;
use WeakMap;
use WeakReference;

/**
 * Opt-in runtime aggregates (`docs/runtime-schema.json`, SPEC section 13).
 *
 * Listens to Laravel events, aggregates in memory per type, dimensions and
 * UTC minute (counts, total and maximum milliseconds or megabytes where the
 * type has them, and a latency histogram for durations) and writes the
 * aggregates to the local buffer when the request or command terminates,
 * and after each queued job, scheduled task or Artisan command (a queue worker terminates only
 * when it stops). Nothing is sent from here: `excubia:push-runtime` sends
 * the buffer.
 *
 * What is kept: route names (or `unnamed`), HTTP methods and status classes,
 * SQL text without bindings (quoted literals masked) and the connection name,
 * job class, queue and connection names, scheduled task names and ids
 * (`ScheduledTaskIdentityService`), outgoing hosts, peak memory, queue wait
 * times, cache store names and masked key prefixes, mailable, mailer, notification and
 * channel names, log levels and log channel names, the source package, masked message and PHP
 * minor version of PHP deprecations, the normalised SQL shape, connection
 * and route name or job class of queries repeated within one request or job
 * (`duplicateQuery`, #148), and Artisan command names with their exit code
 * class (`command`, #152). Never request or response bodies,
 * headers, cookies, users, IP addresses, query bindings, exception messages,
 * whole cache keys or values, recipients, log messages or context, absolute
 * paths, the line and stack of a deprecation, or command arguments and
 * options.
 *
 * Every listener swallows its own exceptions: monitoring must never break or
 * slow down the host application. Excubia never logs, and the log listener
 * ignores messages logged while it runs, so it cannot loop.
 *
 * ponytail: the aggregates are flushed by `terminating()`; under Octane that
 * runs per request on the sandbox. A worker that never terminates and runs no
 * job keeps at most MAX_PENDING_AGGREGATES in memory before it writes them.
 *
 * ponytail: `duplicateQuery` evaluates the queries of an HTTP request at
 * `RequestHandled` and those of a queued job when it ends. Queries outside
 * both (Artisan commands, scheduled tasks, a worker between jobs) are never
 * evaluated and are dropped when the process terminates; a command or task
 * scope is the upgrade path.
 *
 * ponytail: request peak memory is the process peak; under Octane it only
 * grows across requests. Reset it per request if Octane users need it.
 */
final class RuntimeCollectorService
{
    public const MAX_PENDING_AGGREGATES = 500;

    public const MAX_SQL_LENGTH = 2000;

    /**
     * Key added to every job payload when `queueWait` is on: the Unix time,
     * with microseconds, when the job was pushed.
     */
    public const QUEUED_AT_PAYLOAD_KEY = 'excubiaQueuedAt';

    public const MASKED_KEY_PREFIX = '[masked]';

    public const MAX_DEPRECATION_MESSAGE_LENGTH = 300;

    /**
     * Distinct query shapes counted per request or job (`duplicateQuery`);
     * a new shape past it is ignored, so memory stays bounded.
     */
    public const MAX_QUERY_SHAPES = 200;

    /**
     * Raw SQL longer than this is normalised on every execution instead of
     * being remembered, so the memo of normalised SQL stays small.
     */
    private const MAX_MEMOISED_SQL_LENGTH = 4000;

    /**
     * Environment variable the scheduler commands set for the task processes
     * they start (#152), so a scheduled Artisan command, already counted as
     * `scheduledTask`, is not counted again as `command`.
     */
    public const SCHEDULER_ENVIRONMENT_VARIABLE = 'EXCUBIA_INSIDE_SCHEDULER';

    /**
     * Commands that run scheduled tasks: the ones they start, in the same
     * process or as child processes, are `scheduledTask`, never `command`.
     */
    private const SCHEDULER_COMMANDS = ['schedule:run', 'schedule:work', 'schedule:test'];

    private const BYTES_PER_MEGABYTE = 1048576;

    private const LOG_LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /** @var array<string, array<string, mixed>> */
    private array $aggregates = [];

    /** @var WeakMap<object, float> start time of each running job */
    private WeakMap $jobStartedAt;

    /** @var WeakMap<object, true> tasks recorded as failed by ScheduledTaskFinished, before their ScheduledTaskFailed */
    private WeakMap $recordedTasks;

    /**
     * Highest peak seen before a job reset the PHP peak (`jobMemory`), so a
     * request that runs sync jobs still reports its own peak.
     */
    private int $peakMemoryBytes = 0;

    /**
     * Mailable and mailer of the message being sent: `MessageSending` sets
     * it, `MessageSent` clears it, and one still set when its scope ends is
     * a failed send (`settlePendingMail()`).
     *
     * @var array{mailable: string, mailer: string}|null
     */
    private ?array $pendingMail = null;

    private bool $isRecordingLog = false;

    /**
     * Log channel of the entry being written, set by the Monolog processor
     * `watchLogChannel()` pushes and read (then cleared) by the next
     * `MessageLogged`, which Laravel fires after Monolog ran the processors.
     */
    private ?string $logChannel = null;

    /**
     * The `datetime` of the log record `logChannel` belongs to. Each record
     * has its own; a stack runs its own processor first and then the ones
     * it copied from the channels inside it, so the first name set for a
     * record wins and a stack reports the stack's name.
     *
     * @var WeakReference<object>|null
     */
    private ?WeakReference $logChannelRecord = null;

    private bool $isRecordingDeprecation = false;

    /** @var list<string>|null */
    private ?array $projectPrefixes = null;

    private readonly bool $isCountingQueryShapes;

    /**
     * Executions per normalised SQL and connection in the current request or
     * job (`duplicateQuery`), at most MAX_QUERY_SHAPES shapes.
     *
     * @var array<string, array<string, int>> connection => normalised SQL => executions
     */
    private array $queryShapes = [];

    private int $queryShapeCount = 0;

    /**
     * The counts of the request or job around each running job, put back
     * when the job ends, so a sync job inside a request is counted apart.
     *
     * @var WeakMap<object, array{array<string, array<string, int>>, int}>
     */
    private WeakMap $outerQueryShapes;

    /**
     * Normalised SQL by raw SQL, so a query repeated in a loop is normalised
     * once. At most MAX_QUERY_SHAPES entries; cleared when full.
     *
     * @var array<string, string>
     */
    private array $normalisedSql = [];

    /**
     * Artisan commands running in this process: 1 for the top-level one,
     * more while it calls others through `Artisan::call()` (`command`).
     */
    private int $commandDepth = 0;

    /**
     * Start time of the top-level command when `command` counts it, else null
     * (ignored, Excubia's own, or run by the scheduler).
     */
    private ?float $commandStartedAt = null;

    /**
     * Whether this process set SCHEDULER_ENVIRONMENT_VARIABLE for the
     * scheduler command running now; it is removed when that command ends,
     * so a long-lived process that called `schedule:run` keeps counting.
     */
    private bool $hasMarkedScheduler = false;

    /**
     * @param  (Closure(): float)|null  $clock  current Unix time with microseconds; tests pass a fixed one
     */
    public function __construct(
        private readonly RuntimeConfigurationData $configuration,
        private readonly RuntimeBufferService $buffer,
        private readonly string $basePath,
        private readonly ?Closure $clock = null,
    ) {
        $this->jobStartedAt = new WeakMap;
        $this->recordedTasks = new WeakMap;
        $this->outerQueryShapes = new WeakMap;
        $this->isCountingQueryShapes = $configuration->isTypeEnabled(RuntimeTypeEnum::DuplicateQuery);
    }

    /**
     * Register the collector, its listeners and the terminating flush. Only
     * the enabled types get a listener.
     */
    public static function register(Application $application, RuntimeConfigurationData $configuration): void
    {
        $collector = new self(
            $configuration,
            new RuntimeBufferService($application->storagePath('excubia'.DIRECTORY_SEPARATOR.'runtime'), $configuration->bufferMaxBytes),
            $application->basePath(),
        );
        $application->instance(self::class, $collector);

        /** @var Dispatcher $events */
        $events = $application->make('events');

        $isCountingQueryShapes = $configuration->isTypeEnabled(RuntimeTypeEnum::DuplicateQuery);

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Request) || $configuration->isTypeEnabled(RuntimeTypeEnum::RequestMemory) || $isCountingQueryShapes) {
            $events->listen(RequestHandled::class, fn (RequestHandled $event) => $collector->recordRequest($event));
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::SlowQuery) || $isCountingQueryShapes) {
            $events->listen(QueryExecuted::class, fn (QueryExecuted $event) => $collector->recordQuery($event));
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Mail)) {
            $events->listen(MessageSending::class, fn (MessageSending $event) => $collector->rememberMail($event));
            $events->listen(MessageSent::class, fn (MessageSent $event) => $collector->recordMail($event));
            // Registered before the job and command listeners, which flush: a failed send lands in that flush.
            $events->listen(
                [JobProcessed::class, JobExceptionOccurred::class, JobFailed::class, CommandFinished::class],
                fn () => $collector->settlePendingMail(),
            );
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Job) || $configuration->isTypeEnabled(RuntimeTypeEnum::JobMemory) || $configuration->isTypeEnabled(RuntimeTypeEnum::QueueWait) || $isCountingQueryShapes) {
            $events->listen(JobProcessing::class, fn (JobProcessing $event) => $collector->startJob($event));
            $events->listen(JobProcessed::class, fn (JobProcessed $event) => $collector->recordJob($event));
            $events->listen(JobFailed::class, fn (JobFailed $event) => $collector->recordJob($event));
        }

        if ($isCountingQueryShapes) {
            // A job that throws and is released for a retry fires neither JobProcessed nor JobFailed.
            $events->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $event) => $collector->recordJobQueryShapes($event));
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::QueueWait)) {
            Queue::createPayloadUsing(static fn (): array => [self::QUEUED_AT_PAYLOAD_KEY => microtime(true)]);
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Cache)) {
            $events->listen(
                [CacheHit::class, CacheMissed::class, KeyWritten::class, KeyForgotten::class],
                fn (CacheHit|CacheMissed|KeyWritten|KeyForgotten $event) => $collector->recordCache($event),
            );
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Notification)) {
            $events->listen(
                [NotificationSent::class, NotificationFailed::class],
                fn (NotificationSent|NotificationFailed $event) => $collector->recordNotification($event),
            );
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::LogLevel)) {
            $events->listen(MessageLogged::class, fn (MessageLogged $event) => $collector->recordLog($event));
            $collector->tapLogChannels($application);
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Deprecation)) {
            $collector->listenToDeprecations();
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::ScheduledTask)) {
            $events->listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => $collector->recordScheduledTask($event));
            $events->listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => $collector->recordScheduledTask($event));
            $events->listen(ScheduledTaskSkipped::class, fn (ScheduledTaskSkipped $event) => $collector->recordScheduledTask($event));
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::OutgoingHttp)) {
            $events->listen(ResponseReceived::class, fn (ResponseReceived $event) => $collector->recordOutgoingHttp($event));
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Command)) {
            $events->listen(CommandStarting::class, fn (CommandStarting $event) => $collector->startCommand($event));
            $events->listen(CommandFinished::class, fn (CommandFinished $event) => $collector->recordCommand($event));
        }

        $application->terminating(function () use ($collector): void {
            $collector->settlePendingMail();
            $collector->forgetQueryShapes();
            $collector->flush();
        });
    }

    public function recordRequest(RequestHandled $event): void
    {
        $this->guard(function () use ($event): void {
            $route = $event->request->route();
            $routeName = $route instanceof Route ? $route->getName() : null;
            $dimensions = [
                'route' => is_string($routeName) && $routeName !== '' ? $routeName : 'unnamed',
                'method' => strtoupper($event->request->getMethod()),
            ];

            if ($this->shouldRecord(RuntimeTypeEnum::Request)) {
                $startedAt = $event->request->server('REQUEST_TIME_FLOAT');
                $status = $event->response->getStatusCode();

                $this->add(RuntimeTypeEnum::Request, [
                    ...$dimensions,
                    'statusClass' => self::statusClass($status),
                ], is_numeric($startedAt) ? ($this->now() - (float) $startedAt) * 1000 : 0.0, $status >= 500);
            }

            if ($this->shouldRecord(RuntimeTypeEnum::RequestMemory)) {
                $this->addMemory(RuntimeTypeEnum::RequestMemory, $dimensions, max($this->peakMemoryBytes, memory_get_peak_usage()));
            }

            if ($this->isCountingQueryShapes) {
                $this->recordQueryShapes('request', $dimensions['route']);
            }
        });
    }

    public function recordQuery(QueryExecuted $event): void
    {
        $this->guard(function () use ($event): void {
            if ($this->isCountingQueryShapes) {
                $this->countQueryShape($event->sql, (string) $event->connectionName);
            }

            if ($event->time < $this->configuration->slowQueryThresholdMs || ! $this->shouldRecord(RuntimeTypeEnum::SlowQuery)) {
                return;
            }

            $this->add(RuntimeTypeEnum::SlowQuery, [
                'sql' => self::maskSql($event->sql),
                'connection' => (string) $event->connectionName,
            ], (float) $event->time, false);
        });
    }

    /**
     * Drop the query shapes counted so far: after each request and job, and
     * when the process terminates (queries outside a request or job).
     */
    public function forgetQueryShapes(): void
    {
        $this->queryShapes = [];
        $this->queryShapeCount = 0;
    }

    /**
     * Evaluate the query shapes of a job that ends (processed, failed, or
     * thrown and released), then put back the counts of the request or job
     * around it. A job whose start was not seen, or that was already
     * evaluated (`JobExceptionOccurred` then `JobFailed`), is skipped.
     */
    public function recordJobQueryShapes(JobProcessed|JobFailed|JobExceptionOccurred $event): void
    {
        $this->guard(function () use ($event): void {
            $outer = $this->outerQueryShapes[$event->job] ?? null;

            if ($outer === null) {
                return;
            }

            unset($this->outerQueryShapes[$event->job]);

            try {
                $this->recordQueryShapes('job', $this->className($event->job->resolveName()));
            } finally {
                [$this->queryShapes, $this->queryShapeCount] = $outer;
            }
        });
    }

    /**
     * One execution of a query shape: the SQL normalised (`normaliseSql()`)
     * per connection. Bindings are never read. A new shape past
     * MAX_QUERY_SHAPES is ignored.
     */
    private function countQueryShape(string $sql, string $connection): void
    {
        $normalised = $this->normalisedSql[$sql] ?? null;

        if ($normalised === null) {
            $normalised = self::normaliseSql($sql);

            if (strlen($sql) <= self::MAX_MEMOISED_SQL_LENGTH) {
                if (count($this->normalisedSql) >= self::MAX_QUERY_SHAPES) {
                    $this->normalisedSql = [];
                }

                $this->normalisedSql[$sql] = $normalised;
            }
        }

        if (isset($this->queryShapes[$connection][$normalised])) {
            $this->queryShapes[$connection][$normalised]++;

            return;
        }

        if ($this->queryShapeCount >= self::MAX_QUERY_SHAPES) {
            return;
        }

        $this->queryShapes[$connection][$normalised] = 1;
        $this->queryShapeCount++;
    }

    /**
     * Record one `duplicateQuery` occurrence per query shape of the request
     * or job that ran at least `runtime.duplicateQueries.threshold` times,
     * then forget the counts. The sample rate applies per request or job.
     *
     * @param  'request'|'job'  $origin
     */
    private function recordQueryShapes(string $origin, string $name): void
    {
        $queryShapes = $this->queryShapes;
        $this->forgetQueryShapes();

        if ($queryShapes === [] || ! $this->isSampled(RuntimeTypeEnum::DuplicateQuery)) {
            return;
        }

        foreach ($queryShapes as $connection => $executions) {
            foreach ($executions as $sql => $repeats) {
                if ($repeats < $this->configuration->duplicateQueryThreshold) {
                    continue;
                }

                $this->record(RuntimeTypeEnum::DuplicateQuery, [
                    'origin' => $origin,
                    'name' => $name,
                    'sql' => (string) $sql,
                    'connection' => (string) $connection,
                ], ['totalRepeats' => $repeats, 'maxRepeats' => $repeats]);
            }
        }
    }

    /**
     * Start timing the job, record its queue wait (`queueWait`) and, for
     * `jobMemory`, reset the PHP peak so the job's own peak can be read when
     * it ends (the earlier peak is kept for the request around it). For
     * `duplicateQuery`, set the counts of the request or job around it aside.
     */
    public function startJob(JobProcessing $event): void
    {
        $this->guard(function () use ($event): void {
            $this->jobStartedAt[$event->job] = $this->now();

            if ($this->isCountingQueryShapes) {
                $this->outerQueryShapes[$event->job] = [$this->queryShapes, $this->queryShapeCount];
                $this->forgetQueryShapes();
            }

            if ($this->shouldRecord(RuntimeTypeEnum::QueueWait)) {
                $this->recordQueueWait($event);
            }

            if ($this->configuration->isTypeEnabled(RuntimeTypeEnum::JobMemory)) {
                $this->peakMemoryBytes = max($this->peakMemoryBytes, memory_get_peak_usage());
                memory_reset_peak_usage();
            }
        });
    }

    public function recordJob(JobProcessed|JobFailed $event): void
    {
        $this->guard(function () use ($event): void {
            $startedAt = $this->jobStartedAt[$event->job] ?? null;
            unset($this->jobStartedAt[$event->job]);

            if ($this->isCountingQueryShapes) {
                $this->recordJobQueryShapes($event);
            }

            $job = $this->className($event->job->resolveName());
            $queue = (string) $event->job->getQueue();

            if ($this->shouldRecord(RuntimeTypeEnum::Job)) {
                $isFailed = $event instanceof JobFailed;

                $this->add(RuntimeTypeEnum::Job, [
                    'job' => $job,
                    'queue' => $queue,
                    'connection' => (string) $event->connectionName,
                    'outcome' => $isFailed ? 'failed' : 'processed',
                ], $startedAt === null ? 0.0 : ($this->now() - $startedAt) * 1000, $isFailed);
            }

            if ($this->configuration->isTypeEnabled(RuntimeTypeEnum::JobMemory)) {
                $peakBytes = memory_get_peak_usage();
                $this->peakMemoryBytes = max($this->peakMemoryBytes, $peakBytes);

                if ($this->isSampled(RuntimeTypeEnum::JobMemory)) {
                    $this->addMemory(RuntimeTypeEnum::JobMemory, ['job' => $job, 'queue' => $queue], $peakBytes);
                }
            }

            $this->flush();
        });
    }

    /**
     * Wait between the push and the first attempt, without the job's
     * delay. The push time is the `excubiaQueuedAt` key this collector adds
     * to every payload, else Horizon's `pushedAt`. Retries are skipped: a
     * released job keeps its first push time, so its wait would include the
     * earlier attempts and the backoff.
     *
     * ponytail: the push and the worker may run on different hosts; clock
     * skew between them shows up in the wait (negative waits become 0).
     */
    private function recordQueueWait(JobProcessing $event): void
    {
        if ($event->job->attempts() > 1) {
            return;
        }

        $payload = $event->job->payload();
        $queuedAt = $payload[self::QUEUED_AT_PAYLOAD_KEY] ?? $payload['pushedAt'] ?? null;

        if (! is_numeric($queuedAt)) {
            return;
        }

        $delaySeconds = $payload['delay'] ?? null;
        $delaySeconds = is_numeric($delaySeconds) ? (float) $delaySeconds : 0.0;

        $this->add(RuntimeTypeEnum::QueueWait, [
            'job' => $this->className($event->job->resolveName()),
            'queue' => (string) $event->job->getQueue(),
        ], ($this->now() - (float) $queuedAt - $delaySeconds) * 1000, false);
    }

    /**
     * One hit, miss, write or delete per cache store and masked key prefix.
     */
    public function recordCache(CacheHit|CacheMissed|KeyWritten|KeyForgotten $event): void
    {
        $this->guard(function () use ($event): void {
            if (! $this->isSampled(RuntimeTypeEnum::Cache)) {
                return;
            }

            $this->record(RuntimeTypeEnum::Cache, [
                'store' => is_string($event->storeName) && $event->storeName !== '' ? $event->storeName : 'default',
                'keyPrefix' => $this->keyPrefix((string) $event->key),
            ], [
                'hits' => $event instanceof CacheHit ? 1 : 0,
                'misses' => $event instanceof CacheMissed ? 1 : 0,
                'writes' => $event instanceof KeyWritten ? 1 : 0,
                'deletes' => $event instanceof KeyForgotten ? 1 : 0,
            ]);
        });
    }

    /**
     * The part of a cache key before its first `:` or `.`, through the
     * exception message masks. A prefix that still looks like an id, a
     * token or personal data (any digit, `@`, a space or other punctuation,
     * hex-only text, more than 64 characters, nothing but a mask
     * placeholder such as `[uuid]`) becomes `[masked]`: only letters, `_`,
     * `-`, `/` and the masks' own `[…]` placeholders inside a word pass.
     */
    public function keyPrefix(string $key): string
    {
        $prefix = preg_split('/[:.]/', $key, 2)[0] ?? '';
        $masked = ExceptionPayloadService::mask($prefix, $this->basePath);

        if (preg_match('#^[A-Za-z_/\[\]-]{1,64}$#', $masked) !== 1 || preg_match('/^(?:[0-9a-f]{8,}|\[\w+\])$/i', $masked) === 1) {
            return self::MASKED_KEY_PREFIX;
        }

        return $masked;
    }

    /**
     * Remember the message being sent. A message still pending from an
     * earlier `MessageSending` never reached `MessageSent`, so it is counted
     * as failed first (the app caught the transport exception and went on).
     */
    public function rememberMail(MessageSending $event): void
    {
        $this->guard(function () use ($event): void {
            $this->settlePendingMail();
            $this->pendingMail = $this->mailDimensions($event->data);
        });
    }

    public function recordMail(MessageSent $event): void
    {
        $this->guard(function () use ($event): void {
            $this->pendingMail = null;

            if ($this->isSampled(RuntimeTypeEnum::Mail)) {
                $this->record(RuntimeTypeEnum::Mail, $this->mailDimensions($event->data), []);
            }
        });
    }

    /**
     * Count the pending message as one failed mail (#175). Laravel has no
     * mail failure event, but the mailer fires `MessageSent` right after the
     * transport returns: a `MessageSending` with no `MessageSent` by the end
     * of its scope (the next send, the end of the queued job or attempt, the
     * end of the Artisan command, or the request or process terminating) is
     * a send that threw. This is the only source of mail failures, so it
     * works with exception capture off and counts each send once with it on.
     *
     * ponytail: a `MessageSending` listener registered after Excubia's that
     * cancels the send (returns false), or one that throws, is counted as a
     * failed mail; a job or command that runs inside a `MessageSending`
     * listener (a queued listener on the sync driver) counts the send as
     * failed and then as sent. Wrapping the mail transport is the upgrade
     * path if that ever matters.
     */
    public function settlePendingMail(): void
    {
        $this->guard(function (): void {
            $dimensions = $this->pendingMail;
            $this->pendingMail = null;

            if ($dimensions !== null && $this->isSampled(RuntimeTypeEnum::Mail)) {
                $this->record(RuntimeTypeEnum::Mail, $dimensions, ['errorCount' => 1]);
            }
        });
    }

    public function recordNotification(NotificationSent|NotificationFailed $event): void
    {
        $this->guard(function () use ($event): void {
            if (! $this->isSampled(RuntimeTypeEnum::Notification)) {
                return;
            }

            $channel = $event->channel;

            $this->record(RuntimeTypeEnum::Notification, [
                'notification' => is_object($event->notification) ? $this->className($event->notification::class) : 'unknown',
                'channel' => is_string($channel) && $channel !== '' ? $this->className($channel) : 'unknown',
            ], ['errorCount' => $event instanceof NotificationFailed ? 1 : 0]);
        });
    }

    /**
     * Learn the channel name of each log entry (#174) without touching the
     * message or the context: append `LogChannelTapService` to the `tap`
     * list of every configured channel, so Laravel calls it when it
     * resolves the channel, and watch the channels it already resolved.
     * The other taps of a channel are kept and run first.
     *
     * Why a tap and a processor, not `LogManager::extend()` or a handler:
     * `MessageLogged` carries no channel, but Laravel fires it right after
     * Monolog wrote the record, and Monolog runs the processors before the
     * handlers. A tap keeps every driver as configured and is lazy.
     *
     * ponytail: on-demand channels (`Log::build()`, `Log::stack([...])`)
     * have no configuration to tap, so their entries have no channel unless
     * they copy a tapped channel's processors (a stack of named channels
     * reports the first one). An entry whose handler throws fires no
     * `MessageLogged`; its channel is overwritten by the next entry.
     */
    public function tapLogChannels(Application $application): void
    {
        $this->guard(function () use ($application): void {
            /** @var Repository $config */
            $config = $application->make('config');
            $channels = $config->get('logging.channels');

            if (is_array($channels)) {
                foreach ($channels as $name => $channel) {
                    if (! is_string($name) || $name === '' || ! is_array($channel)) {
                        continue;
                    }

                    $taps = is_array($channel['tap'] ?? null) ? $channel['tap'] : [];
                    $tap = LogChannelTapService::class.':'.$name;

                    if (! in_array($tap, $taps, true)) {
                        $channels[$name]['tap'] = [...$taps, $tap];
                    }
                }

                $config->set('logging.channels', $channels);
            }

            if (! $application->resolved('log')) {
                return;
            }

            $manager = $application->make('log');

            if (! $manager instanceof LogManager) {
                return;
            }

            foreach ($manager->getChannels() as $name => $logger) {
                if (is_string($name) && $logger instanceof Logger) {
                    $this->watchLogChannel($logger, $name);
                }
            }
        });
    }

    /**
     * Push a Monolog processor onto the channel's logger that remembers the
     * channel name for the next `MessageLogged`. The processor returns the
     * record unchanged and never reads its message or context.
     */
    public function watchLogChannel(Logger $logger, string $channel): void
    {
        $this->guard(function () use ($logger, $channel): void {
            $monolog = $logger->getLogger();

            if ($channel === '' || ! $monolog instanceof MonologLogger) {
                return;
            }

            $monolog->pushProcessor(function (LogRecord $record) use ($channel): LogRecord {
                if ($this->logChannelRecord?->get() !== $record->datetime) {
                    $this->logChannel = $channel;
                    $this->logChannelRecord = WeakReference::create($record->datetime);
                }

                return $record;
            });
        });
    }

    /**
     * Count one log entry by level and, when a watched channel wrote it,
     * channel. The message and context are never read. A message logged
     * while this runs (a deprecation raised inside Excubia) is ignored, so
     * the listener cannot loop.
     */
    public function recordLog(MessageLogged $event): void
    {
        $channel = $this->logChannel;
        $this->logChannel = null;
        $this->logChannelRecord = null;

        if ($this->isRecordingLog) {
            return;
        }

        $this->isRecordingLog = true;

        try {
            $this->guard(function () use ($event, $channel): void {
                if (! $this->isSampled(RuntimeTypeEnum::LogLevel)) {
                    return;
                }

                $level = is_string($event->level) ? strtolower($event->level) : '';
                $dimensions = ['level' => in_array($level, self::LOG_LEVELS, true) ? $level : 'unknown'];

                if ($channel !== null) {
                    $dimensions['channel'] = $channel;
                }

                $this->record(RuntimeTypeEnum::LogLevel, $dimensions, []);
            });
        } finally {
            $this->isRecordingLog = false;
        }
    }

    /**
     * Chain a PHP error handler in front of the current one (Laravel's
     * `HandleExceptions`, which writes deprecations to the `deprecations`
     * log channel, when the provider boots). It records `E_DEPRECATED` and
     * `E_USER_DEPRECATED`, then always calls the previous handler with the
     * same arguments and returns its result, so Laravel's deprecation log,
     * its ErrorException for warnings, and any other chained handler (Sentry,
     * Flare…) behave as before. With no previous handler it returns false and
     * PHP's own handling continues.
     *
     * The handler is registered for every level, not with a level mask: PHP
     * sends the levels outside a mask to its built-in handler, not to the
     * previous one, so a mask would stop Laravel turning warnings into
     * exceptions. A request that raises no PHP error never calls it.
     *
     * Why the error handler and not Laravel's `deprecations` channel: the
     * `MessageLogged` event carries no channel, the log line holds the
     * absolute file and line in its text, and Laravel skips that channel in
     * unit tests and before the app is bootstrapped. The handler sees the
     * file directly and counts the same deprecations whatever the logging
     * configuration, and Laravel's channel keeps working because the handler
     * forwards to it.
     *
     * ponytail: a handler set later that does not forward to the previous
     * one hides deprecations from Excubia; it is not re-chained.
     */
    public function listenToDeprecations(): void
    {
        $previousHandler = null;
        $previousHandler = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use (&$previousHandler): mixed {
            if ($level === E_DEPRECATED || $level === E_USER_DEPRECATED) {
                $this->recordDeprecation($message, $file);
            }

            if ($previousHandler === null) {
                return false;
            }

            return $previousHandler($level, $message, $file, $line);
        });
    }

    /**
     * Count one deprecation by source, masked message and PHP minor
     * version. The line and the stack are never read. A deprecation raised
     * while this runs is not counted (it is still forwarded), so the
     * collector cannot loop.
     */
    public function recordDeprecation(string $message, string $file): void
    {
        if ($this->isRecordingDeprecation) {
            return;
        }

        $this->isRecordingDeprecation = true;

        try {
            $this->guard(function () use ($message, $file): void {
                if (! $this->isSampled(RuntimeTypeEnum::Deprecation)) {
                    return;
                }

                $masked = mb_strimwidth(ExceptionPayloadService::mask($message, $this->basePath), 0, self::MAX_DEPRECATION_MESSAGE_LENGTH, '…');

                $this->record(RuntimeTypeEnum::Deprecation, [
                    'source' => $this->deprecationSource($file),
                    'message' => $masked === '' ? 'unknown' : $masked,
                    'phpVersion' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
                ], []);
            });
        } finally {
            $this->isRecordingDeprecation = false;
        }
    }

    /**
     * `vendor/<vendor>/<package>` for a file under the project's `vendor/`
     * (`vendor/<directory>` for Composer's own files), `app` for any other
     * file inside the project (compiled views included), `unknown` for a
     * file outside it or no file. Never the path itself.
     *
     * ponytail: assumes Composer's default `vendor/` directory; a custom
     * `vendor-dir` counts as `app`, and a path repository symlinked into
     * `vendor/` reports its real path.
     */
    public function deprecationSource(string $file): string
    {
        $file = str_replace('\\', '/', preg_replace("/\\(\\d+\\) : eval\\(\\)'d code$/", '', $file) ?? $file);
        $relative = null;

        foreach ($this->projectPrefixes() as $prefix) {
            if ($file !== '' && str_starts_with($file, $prefix)) {
                $relative = substr($file, strlen($prefix));

                break;
            }
        }

        if ($relative === null) {
            return 'unknown';
        }

        if (preg_match('#^vendor/([^/]+)(?:/([^/]+)/)?#', $relative, $matches) !== 1) {
            return 'app';
        }

        return isset($matches[2]) ? 'vendor/'.$matches[1].'/'.$matches[2] : 'vendor/'.$matches[1];
    }

    /**
     * The project root with a trailing `/`, as configured and resolved
     * (PHP reports included files by their real path, so a root reached
     * through a symlink, such as `/var` on macOS, still matches). Computed
     * on the first deprecation only.
     *
     * @return list<string>
     */
    private function projectPrefixes(): array
    {
        if ($this->projectPrefixes !== null) {
            return $this->projectPrefixes;
        }

        $roots = [$this->basePath];
        $realPath = realpath($this->basePath);

        if ($realPath !== false) {
            $roots[] = $realPath;
        }

        $this->projectPrefixes = array_values(array_unique(array_map(
            static fn (string $root): string => rtrim(str_replace('\\', '/', $root), '/').'/',
            $roots,
        )));

        return $this->projectPrefixes;
    }

    /**
     * A command that exits non-zero fires `ScheduledTaskFinished` and then
     * `ScheduledTaskFailed`; it is recorded once, as failed, with its runtime.
     */
    public function recordScheduledTask(ScheduledTaskFinished|ScheduledTaskFailed|ScheduledTaskSkipped $event): void
    {
        $this->guard(function () use ($event): void {
            if ($event instanceof ScheduledTaskFailed && isset($this->recordedTasks[$event->task])) {
                unset($this->recordedTasks[$event->task]);

                return;
            }

            $hasExitedWithError = (int) ($event->task->exitCode ?? 0) !== 0;

            if ($event instanceof ScheduledTaskFinished && $hasExitedWithError) {
                $this->recordedTasks[$event->task] = true;
            }

            if ($this->isSampled(RuntimeTypeEnum::ScheduledTask)) {
                $outcome = match (true) {
                    $event instanceof ScheduledTaskSkipped => 'skipped',
                    $event instanceof ScheduledTaskFailed, $hasExitedWithError => 'failed',
                    default => 'finished',
                };
                // ScheduledTaskFailed carries no runtime on Laravel 12.
                $runtimeSeconds = $event instanceof ScheduledTaskSkipped ? null : ($event->runtime ?? null);

                $task = ScheduledTaskIdentityService::name($event->task, $this->basePath);
                $expression = $event->task->expression ?? null;
                $dimensions = is_string($expression) ? ['task' => $task, 'taskId' => ScheduledTaskIdentityService::id($task, $expression)] : ['task' => $task];

                $this->add(RuntimeTypeEnum::ScheduledTask, [
                    ...$dimensions,
                    'outcome' => $outcome,
                ], is_numeric($runtimeSeconds) ? (float) $runtimeSeconds * 1000 : 0.0, $outcome === 'failed');
            }

            $this->flush();
        });
    }

    /**
     * Start timing an Artisan command (`command`, #152). Only the top-level
     * command of the process counts: one it calls through `Artisan::call()`
     * is part of its duration, and a command called from a scheduled closure
     * or job runs below `schedule:run`. A scheduler command marks the
     * environment its task processes inherit, so a scheduled Artisan command
     * (a child `php artisan …` process, top-level there) is skipped as well:
     * it is already a `scheduledTask`. Ignored commands and Excubia's own are
     * skipped, before the sample rate is applied.
     */
    public function startCommand(CommandStarting $event): void
    {
        $this->commandDepth++;

        if ($this->commandDepth !== 1) {
            return;
        }

        $this->commandStartedAt = null;

        $this->guard(function () use ($event): void {
            $isInsideScheduler = self::isInsideScheduler();

            if (! $isInsideScheduler && in_array($event->command, self::SCHEDULER_COMMANDS, true)) {
                self::markScheduler(true);
                $this->hasMarkedScheduler = true;
            }

            if ($isInsideScheduler || $this->isIgnoredCommand($event->command)) {
                return;
            }

            $this->commandStartedAt = $this->now();
        });
    }

    /**
     * Record the top-level command by name and exit code class, then flush:
     * a long-lived process (Octane, a worker calling `Artisan::call()`) does
     * not terminate after each command. In a short-lived `artisan` process
     * the terminating flush that follows finds nothing left to write. The
     * input (arguments and options) is never read.
     */
    public function recordCommand(CommandFinished $event): void
    {
        $this->commandDepth = max($this->commandDepth - 1, 0);

        if ($this->commandDepth !== 0) {
            return;
        }

        if ($this->hasMarkedScheduler) {
            $this->hasMarkedScheduler = false;
            self::markScheduler(false);
        }

        if ($this->commandStartedAt === null) {
            return;
        }

        $startedAt = $this->commandStartedAt;
        $this->commandStartedAt = null;

        $this->guard(function () use ($event, $startedAt): void {
            if ($this->isSampled(RuntimeTypeEnum::Command)) {
                $this->add(RuntimeTypeEnum::Command, [
                    'command' => $event->command !== '' ? $event->command : 'unknown',
                    'exitCodeClass' => match ($event->exitCode) {
                        0 => '0',
                        1 => '1',
                        default => 'other',
                    },
                ], ($this->now() - $startedAt) * 1000, $event->exitCode !== 0);
            }

            $this->flush();
        });
    }

    /**
     * Excubia's own commands (`excubia:*`) always, then the configured
     * `runtime.commands.ignore` names and `*` patterns.
     */
    private function isIgnoredCommand(string $command): bool
    {
        return Str::is(['excubia:*', ...$this->configuration->ignoredCommands], $command);
    }

    /**
     * ponytail: the marker reaches every task process the scheduler starts
     * through Symfony Process, which inherits `$_ENV`; a task run with
     * `->user()` goes through `sudo`, which resets the environment, so such a
     * scheduled Artisan command is also counted as `command`. Laravel's own
     * marker for scheduled processes would be the upgrade path, if one lands.
     */
    private static function markScheduler(bool $isInside): void
    {
        if ($isInside) {
            $_ENV[self::SCHEDULER_ENVIRONMENT_VARIABLE] = '1';
            putenv(self::SCHEDULER_ENVIRONMENT_VARIABLE.'=1');

            return;
        }

        unset($_ENV[self::SCHEDULER_ENVIRONMENT_VARIABLE]);
        putenv(self::SCHEDULER_ENVIRONMENT_VARIABLE);
    }

    private static function isInsideScheduler(): bool
    {
        return getenv(self::SCHEDULER_ENVIRONMENT_VARIABLE) === '1' || ($_ENV[self::SCHEDULER_ENVIRONMENT_VARIABLE] ?? null) === '1';
    }

    public function recordOutgoingHttp(ResponseReceived $event): void
    {
        $this->guard(function () use ($event): void {
            if (! $this->isSampled(RuntimeTypeEnum::OutgoingHttp)) {
                return;
            }

            $host = parse_url($event->request->url(), PHP_URL_HOST);
            $totalTime = $event->response->handlerStats()['total_time'] ?? null;
            $status = $event->response->status();

            $this->add(RuntimeTypeEnum::OutgoingHttp, [
                'host' => is_string($host) && $host !== '' ? strtolower($host) : 'unknown',
                'statusClass' => self::statusClass($status),
            ], is_numeric($totalTime) ? (float) $totalTime * 1000 : 0.0, $status >= 500);
        });
    }

    /**
     * Write the pending aggregates to the buffer and forget them.
     */
    public function flush(): void
    {
        $this->guard(function (): void {
            if ($this->aggregates === []) {
                return;
            }

            $aggregates = array_values($this->aggregates);
            $this->aggregates = [];
            $this->buffer->append($aggregates);
        });
    }

    /**
     * SQL text without bindings. Literals written inline are masked too,
     * because they can hold the same kind of data as bindings and each
     * distinct value would be a new aggregate: dollar-quoted bodies
     * (`$$…$$`, `$tag$…$tag$`), hex literals (`X'…'`, `0x…`), single-quoted
     * strings and standalone numbers all become `?`. Placeholders (`?`,
     * `$1`) and identifiers with digits (`t1`, `col_2`) stay. Double-quoted
     * and backtick-quoted text is left alone, numbers included: it is an
     * identifier on PostgreSQL, SQLite and MySQL.
     */
    public static function maskSql(string $sql): string
    {
        return mb_strimwidth(self::maskLiterals($sql), 0, self::MAX_SQL_LENGTH, '…');
    }

    /**
     * The shape of a query for `duplicateQuery` (#148): the literals of
     * `maskSql()` masked, runs of whitespace as one space, and a list of
     * placeholders after `IN` (`IN (?, ?, ?)`, also `$1, $2`) as `IN (…)`,
     * so the same query with lists of any length is one shape. At most
     * MAX_SQL_LENGTH characters. The slow query `sql` stays `maskSql()`.
     */
    public static function normaliseSql(string $sql): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim(self::maskLiterals($sql))) ?? '?';
        $normalised = preg_replace('/\b(in) ?\( ?(?:\?|\$\d+)(?: ?, ?(?:\?|\$\d+))* ?\)/i', '$1 (…)', $collapsed) ?? $collapsed;

        return mb_strimwidth($normalised, 0, self::MAX_SQL_LENGTH, '…');
    }

    /**
     * The literals of `maskSql()` replaced by `?`, without the cut.
     */
    private static function maskLiterals(string $sql): string
    {
        $masked = preg_replace(
            [
                '/\$([a-z_]\w*|)\$.*?\$\1\$/is',
                "/\\bx'[0-9a-f]*'|\\b0x[0-9a-f]+\\b/i",
                "/'(?:[^'\\\\]|\\\\.|'')*'/s",
            ],
            '?',
            $sql,
        );
        $masked = $masked === null ? null : preg_replace_callback(
            '/("(?:[^"]|"")*"|`(?:[^`]|``)*`)|(?<![\w$.])\d+(?:\.\d+)?(?![\w.])/',
            static fn (array $matches): string => isset($matches[1]) && $matches[1] !== '' ? $matches[1] : '?',
            $masked,
        );

        return $masked ?? '?';
    }

    /**
     * One timed event: its duration in `totalMs` and `maxMs`, and one count
     * in its latency histogram bucket (#147), so the histogram always adds
     * up to `count`.
     *
     * @param  array<string, string>  $dimensions
     */
    private function add(RuntimeTypeEnum $type, array $dimensions, float $durationMs, bool $isError): void
    {
        $durationMs = round(max($durationMs, 0.0), 3);
        $histogram = array_fill(0, count(RuntimeTypeEnum::HISTOGRAM_BOUNDS_MS) + 1, 0);
        $histogram[RuntimeTypeEnum::histogramBucket($durationMs)]++;

        $this->record($type, $dimensions, ['errorCount' => $isError ? 1 : 0, 'totalMs' => $durationMs, 'maxMs' => $durationMs, 'histogram' => $histogram]);
    }

    /**
     * @param  array<string, string>  $dimensions
     */
    private function addMemory(RuntimeTypeEnum $type, array $dimensions, int $peakBytes): void
    {
        $peakMegabytes = round(max($peakBytes, 0) / self::BYTES_PER_MEGABYTE, 3);

        $this->record($type, $dimensions, ['totalMb' => $peakMegabytes, 'maxMb' => $peakMegabytes]);
    }

    /**
     * Add one event to the pending aggregate with the same dimensions.
     * Every aggregate has `count` 1 and `errorCount` 0 unless `$metrics`
     * says otherwise.
     *
     * @param  array<string, string>  $dimensions
     * @param  array<string, int|float|list<int>>  $metrics
     */
    private function record(RuntimeTypeEnum $type, array $dimensions, array $metrics): void
    {
        $aggregate = [
            'type' => $type->value,
            'bucket' => gmdate('Y-m-d\TH:i:00\Z', (int) $this->now()),
            'sampleRate' => $this->configuration->sampleRate($type),
            ...$dimensions,
            'count' => 1,
            'errorCount' => 0,
            ...$metrics,
        ];
        $key = RuntimeBufferService::mergeKey($aggregate);

        $this->aggregates[$key] = isset($this->aggregates[$key])
            ? RuntimeBufferService::combine($this->aggregates[$key], $aggregate)
            : $aggregate;

        if (count($this->aggregates) >= self::MAX_PENDING_AGGREGATES) {
            $this->flush();
        }
    }

    private function shouldRecord(RuntimeTypeEnum $type): bool
    {
        return $this->configuration->isTypeEnabled($type) && $this->isSampled($type);
    }

    private function isSampled(RuntimeTypeEnum $type): bool
    {
        $sampleRate = $this->configuration->sampleRate($type);

        if ($sampleRate >= 1.0) {
            return true;
        }

        return $sampleRate > 0.0 && mt_rand() / mt_getrandmax() < $sampleRate;
    }

    private function relative(string $value): string
    {
        return ScheduledTaskIdentityService::relative($value, $this->basePath);
    }

    /**
     * A class name without the absolute path of an anonymous class.
     */
    private function className(string $class): string
    {
        return ExceptionPayloadService::className($this->relative($class));
    }

    /**
     * Mailable class (`__laravel_mailable`), else the notification class
     * of a mail notification (`__laravel_notification`), else `unknown`,
     * and the mailer name. Nothing else of the message data is read.
     *
     * @param  array<mixed>  $data
     * @return array{mailable: string, mailer: string}
     */
    private function mailDimensions(array $data): array
    {
        $mailable = $data['__laravel_mailable'] ?? $data['__laravel_notification'] ?? null;
        $mailer = $data['mailer'] ?? null;

        return [
            'mailable' => is_string($mailable) && $mailable !== '' ? $this->className($mailable) : 'unknown',
            'mailer' => is_string($mailer) && $mailer !== '' ? $mailer : 'unknown',
        ];
    }

    private static function statusClass(int $status): string
    {
        return intdiv($status, 100).'xx';
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }

    /**
     * Run collection code; any exception stays inside Excubia.
     *
     * ponytail: errors are dropped, not logged, so a broken buffer directory
     * cannot flood the host app's log; a debug log is the upgrade path.
     */
    private function guard(Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // Monitoring never reaches the host application.
        }
    }
}
