<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Closure;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Route;
use Qiick\Excubia\Data\RuntimeConfigurationData;
use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Services\RuntimeBufferService;
use Throwable;
use WeakMap;

/**
 * Opt-in runtime aggregates (`docs/runtime-schema.json`, SPEC section 13).
 *
 * Listens to Laravel events, aggregates in memory per type, dimensions and
 * UTC minute (count, error count, total and maximum milliseconds) and writes
 * the aggregates to the local buffer when the request or command terminates,
 * and after each queued job or scheduled task (a queue worker terminates
 * only when it stops). Nothing is sent from here: `excubia:push-runtime`
 * sends the buffer.
 *
 * What is kept: route names (or `unnamed`), HTTP methods and status classes,
 * SQL text without bindings (quoted literals masked) and the connection name,
 * job class, queue and connection names, scheduled task descriptions or
 * Artisan commands, outgoing hosts. Never request or response bodies,
 * headers, cookies, users, IP addresses, query bindings, exception messages
 * or absolute paths.
 *
 * Every listener swallows its own exceptions: monitoring must never break or
 * slow down the host application.
 *
 * ponytail: the aggregates are flushed by `terminating()`; under Octane that
 * runs per request on the sandbox. A worker that never terminates and runs no
 * job keeps at most MAX_PENDING_AGGREGATES in memory before it writes them.
 */
final class RuntimeCollectorService
{
    public const MAX_PENDING_AGGREGATES = 500;

    public const MAX_SQL_LENGTH = 2000;

    /** @var array<string, array<string, mixed>> */
    private array $aggregates = [];

    /** @var WeakMap<object, float> start time of each running job */
    private WeakMap $jobStartedAt;

    /** @var WeakMap<object, true> tasks recorded as failed by ScheduledTaskFinished, before their ScheduledTaskFailed */
    private WeakMap $recordedTasks;

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

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Request)) {
            $events->listen(RequestHandled::class, fn (RequestHandled $event) => $collector->recordRequest($event));
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::SlowQuery)) {
            $events->listen(QueryExecuted::class, fn (QueryExecuted $event) => $collector->recordQuery($event));
        }

        if ($configuration->isTypeEnabled(RuntimeTypeEnum::Job)) {
            $events->listen(JobProcessing::class, fn (JobProcessing $event) => $collector->startJob($event));
            $events->listen(JobProcessed::class, fn (JobProcessed $event) => $collector->recordJob($event));
            $events->listen(JobFailed::class, fn (JobFailed $event) => $collector->recordJob($event));
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
            if (! $this->isSampled(RuntimeTypeEnum::Request)) {
                return;
            }

            $route = $event->request->route();
            $routeName = $route instanceof Route ? $route->getName() : null;
            $startedAt = $event->request->server('REQUEST_TIME_FLOAT');
            $status = $event->response->getStatusCode();

            $this->add(RuntimeTypeEnum::Request, [
                'route' => is_string($routeName) && $routeName !== '' ? $routeName : 'unnamed',
                'method' => strtoupper($event->request->getMethod()),
                'statusClass' => self::statusClass($status),
            ], is_numeric($startedAt) ? ($this->now() - (float) $startedAt) * 1000 : 0.0, $status >= 500);
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

    public function startJob(JobProcessing $event): void
    {
        $this->guard(function () use ($event): void {
            $this->jobStartedAt[$event->job] = $this->now();
        });
    }

    public function recordJob(JobProcessed|JobFailed $event): void
    {
        $this->guard(function () use ($event): void {
            $startedAt = $this->jobStartedAt[$event->job] ?? null;
            unset($this->jobStartedAt[$event->job]);

            if ($this->isSampled(RuntimeTypeEnum::Job)) {
                $isFailed = $event instanceof JobFailed;

                $this->add(RuntimeTypeEnum::Job, [
                    'job' => $this->relative($event->job->resolveName()),
                    'queue' => (string) $event->job->getQueue(),
                    'connection' => (string) $event->connectionName,
                    'outcome' => $isFailed ? 'failed' : 'processed',
                ], $startedAt === null ? 0.0 : ($this->now() - $startedAt) * 1000, $isFailed);
            }

            $this->flush();
        });
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
        $aggregate = [
            'type' => $type->value,
            'bucket' => gmdate('Y-m-d\TH:i:00\Z', (int) $this->now()),
            'sampleRate' => $this->configuration->sampleRate($type),
            ...$dimensions,
            'count' => 1,
            'errorCount' => $isError ? 1 : 0,
            'totalMs' => $durationMs,
            'maxMs' => $durationMs,
        ];
        $key = RuntimeBufferService::mergeKey($aggregate);

        $this->aggregates[$key] = isset($this->aggregates[$key])
            ? RuntimeBufferService::combine($this->aggregates[$key], $aggregate)
            : $aggregate;

        if (count($this->aggregates) >= self::MAX_PENDING_AGGREGATES) {
            $this->flush();
        }
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
