<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Closure;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Routing\Route;
use Qiick\Excubia\Data\RuntimeConfigurationData;
use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Services\RuntimeBufferService;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;
use WeakMap;

/**
 * Opt-in runtime aggregates (`docs/runtime-schema.json`, SPEC section 13).
 *
 * Listens to Laravel events, aggregates in memory per type, dimensions and
 * UTC minute (counts, and total and maximum milliseconds or megabytes where
 * the type has them) and writes the aggregates to the local buffer when the
 * request or command terminates, and after each queued job or scheduled task
 * (a queue worker terminates only when it stops). Nothing is sent from here:
 * `excubia:push-runtime` sends the buffer.
 *
 * What is kept: route names (or `unnamed`), HTTP methods and status classes,
 * SQL text without bindings (quoted literals masked) and the connection name,
 * job class, queue and connection names, scheduled task descriptions or
 * Artisan commands, outgoing hosts, peak memory, queue wait times, cache
 * store names and masked key prefixes, mailable, mailer, notification and
 * channel names, log levels, the source package, masked message and PHP
 * minor version of PHP deprecations. Never request or response bodies,
 * headers, cookies, users, IP addresses, query bindings, exception messages,
 * whole cache keys or values, recipients, log messages or context, absolute
 * paths, or the line and stack of a deprecation.
 *
 * Every listener swallows its own exceptions: monitoring must never break or
 * slow down the host application. Excubia never logs, and the log listener
 * ignores messages logged while it runs, so it cannot loop.
 *
 * ponytail: the aggregates are flushed by `terminating()`; under Octane that
 * runs per request on the sandbox. A worker that never terminates and runs no
 * job keeps at most MAX_PENDING_AGGREGATES in memory before it writes them.
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
     * it, `MessageSent` clears it, and a transport failure reported in
     * between is counted against it.
     *
     * @var array{mailable: string, mailer: string}|null
     */
    private ?array $pendingMail = null;

    private bool $isRecordingLog = false;

    private bool $isRecordingDeprecation = false;

    /** @var list<string>|null */
    private ?array $projectPrefixes = null;

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

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Request) || $configuration->isTypeEnabled(RuntimeTypeEnum::RequestMemory)) {
            $events->listen(RequestHandled::class, fn (RequestHandled $event) => $collector->recordRequest($event));
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::SlowQuery)) {
            $events->listen(QueryExecuted::class, fn (QueryExecuted $event) => $collector->recordQuery($event));
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Job) || $configuration->isTypeEnabled(RuntimeTypeEnum::JobMemory) || $configuration->isTypeEnabled(RuntimeTypeEnum::QueueWait)) {
            $events->listen(JobProcessing::class, fn (JobProcessing $event) => $collector->startJob($event));
            $events->listen(JobProcessed::class, fn (JobProcessed $event) => $collector->recordJob($event));
            $events->listen(JobFailed::class, fn (JobFailed $event) => $collector->recordJob($event));
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

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Mail)) {
            $events->listen(MessageSending::class, fn (MessageSending $event) => $collector->rememberMail($event));
            $events->listen(MessageSent::class, fn (MessageSent $event) => $collector->recordMail($event));
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Notification)) {
            $events->listen(
                [NotificationSent::class, NotificationFailed::class],
                fn (NotificationSent|NotificationFailed $event) => $collector->recordNotification($event),
            );
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::LogLevel)) {
            $events->listen(MessageLogged::class, fn (MessageLogged $event) => $collector->recordLog($event));
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

        $application->terminating(fn () => $collector->flush());
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
        });
    }

    public function recordQuery(QueryExecuted $event): void
    {
        $this->guard(function () use ($event): void {
            if ($event->time < $this->configuration->slowQueryThresholdMs || ! $this->isSampled(RuntimeTypeEnum::SlowQuery)) {
                return;
            }

            $this->add(RuntimeTypeEnum::SlowQuery, [
                'sql' => self::maskSql($event->sql),
                'connection' => (string) $event->connectionName,
            ], (float) $event->time, false);
        });
    }

    /**
     * Start timing the job, record its queue wait (`queueWait`) and, for
     * `jobMemory`, reset the PHP peak so the job's own peak can be read when
     * it ends (the earlier peak is kept for the request around it).
     */
    public function startJob(JobProcessing $event): void
    {
        $this->guard(function () use ($event): void {
            $this->jobStartedAt[$event->job] = $this->now();

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

    public function rememberMail(MessageSending $event): void
    {
        $this->guard(function () use ($event): void {
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
     * Count a failed mail when the exception capture hook
     * (`exceptions.enabled`) sees a reported Symfony Mailer transport
     * exception, anywhere in the chain. Laravel has no mail failure event,
     * so a failure the app catches without reporting is not counted. The
     * mailable and mailer are those of the last `MessageSending` that was
     * not followed by `MessageSent`, else `unknown`.
     */
    public function recordMailFailure(Throwable $exception): void
    {
        $this->guard(function () use ($exception): void {
            if (! $this->configuration->isTypeEnabled(RuntimeTypeEnum::Mail) || ! self::isMailTransportFailure($exception)) {
                return;
            }

            $dimensions = $this->pendingMail ?? ['mailable' => 'unknown', 'mailer' => 'unknown'];
            $this->pendingMail = null;

            if ($this->isSampled(RuntimeTypeEnum::Mail)) {
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
     * Count one log entry by level. The message and context are never read.
     * A message logged while this runs (a deprecation raised inside Excubia)
     * is ignored, so the listener cannot loop.
     */
    public function recordLog(MessageLogged $event): void
    {
        if ($this->isRecordingLog) {
            return;
        }

        $this->isRecordingLog = true;

        try {
            $this->guard(function () use ($event): void {
                if (! $this->isSampled(RuntimeTypeEnum::LogLevel)) {
                    return;
                }

                $level = is_string($event->level) ? strtolower($event->level) : '';

                $this->record(RuntimeTypeEnum::LogLevel, [
                    'level' => in_array($level, self::LOG_LEVELS, true) ? $level : 'unknown',
                ], []);
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

                $this->add(RuntimeTypeEnum::ScheduledTask, [
                    'task' => $this->taskName($event->task),
                    'outcome' => $outcome,
                ], is_numeric($runtimeSeconds) ? (float) $runtimeSeconds * 1000 : 0.0, $outcome === 'failed');
            }

            $this->flush();
        });
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
        $masked ??= '?';

        return mb_strimwidth($masked, 0, self::MAX_SQL_LENGTH, '…');
    }

    /**
     * @param  array<string, string>  $dimensions
     */
    private function add(RuntimeTypeEnum $type, array $dimensions, float $durationMs, bool $isError): void
    {
        $durationMs = round(max($durationMs, 0.0), 3);

        $this->record($type, $dimensions, ['errorCount' => $isError ? 1 : 0, 'totalMs' => $durationMs, 'maxMs' => $durationMs]);
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
     * @param  array<string, int|float>  $metrics
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

    /**
     * The task description, else `artisan <command name>` for an Artisan
     * command, else the executable's file name for an `exec()` task, else
     * `Closure`. Arguments are dropped: they can carry secrets in forms no
     * mask recognises (`-pSECRET`, a positional token) and every distinct
     * value would be a new aggregate. The developer's description keeps its
     * text, with paths made relative and the exception message masks applied.
     */
    private function taskName(object $task): string
    {
        $description = $task->description ?? null;

        if (is_string($description) && $description !== '') {
            return ExceptionPayloadService::mask($this->relative($description), $this->basePath);
        }

        $command = $task->command ?? null;

        if (! is_string($command) || $command === '') {
            return 'Closure';
        }

        $artisanPrefix = ArtisanApplication::formatCommandString('');

        if (str_starts_with($command, $artisanPrefix)) {
            return 'artisan '.self::firstWord(substr($command, strlen($artisanPrefix)));
        }

        // Leading environment assignments (`APP_KEY=… backup.sh`) are not the executable.
        $executable = self::firstWord(preg_replace('/^\s*(?:\w+=(?:\'[^\']*\'|"[^"]*"|\S*)\s+)*/', '', $command) ?? '');

        return basename(str_replace('\\', '/', $executable));
    }

    /**
     * The first shell word, without its quotes (`'/opt/my tools/run.sh' --x`
     * gives `/opt/my tools/run.sh`). An empty command gives `unknown`.
     */
    private static function firstWord(string $command): string
    {
        $command = ltrim($command);

        if (preg_match('/^(?:\'([^\']*)\'|"([^"]*)"|(\S+))/', $command, $matches) !== 1) {
            return 'unknown';
        }

        $word = $matches[3] ?? $matches[2] ?? $matches[1];

        return $word === '' ? 'unknown' : $word;
    }

    private function relative(string $value): string
    {
        $withoutBasePath = str_replace([$this->basePath.DIRECTORY_SEPARATOR, $this->basePath], '', $value);

        return preg_replace('#(?<![\w.-])/(?:[^\s\'"/]+/)+([^\s\'"/]+)#', '$1', $withoutBasePath) ?? $withoutBasePath;
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

    private static function isMailTransportFailure(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof TransportExceptionInterface) {
                return true;
            }
        }

        return false;
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
