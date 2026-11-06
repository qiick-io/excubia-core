<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Closure;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Routing\Route;
use Qiick\Excubia\Data\ExceptionConfigurationData;
use Qiick\Excubia\Services\RuntimeBufferService;
use Throwable;
use WeakMap;

/**
 * Opt-in exception capture (`docs/exception-schema.json`, SPEC sections 7
 * and 13, D17).
 *
 * Registers one `reportable()` callback on Laravel's exception handler. The
 * callback returns nothing, so the host app's own reporting (its other
 * callbacks, its log channels, Sentry, Flare…) runs exactly as before, and
 * it only sees what the app reports: `dontReport` and `ShouldntReport`
 * still apply. Each occurrence is masked (`ExceptionPayloadService`),
 * grouped in memory by fingerprint, context and environment, and written to
 * the buffer `storage/excubia/exceptions/` when the request or command
 * terminates, when a queue worker loops, or once MAX_PENDING_GROUPS groups
 * are pending. Nothing is sent from here: `excubia:push-runtime` sends it.
 *
 * Context: the job class (from `JobExceptionOccurred` / `JobFailed`, keyed
 * by the exception), else the matched route name, else the running Artisan
 * command name (or the last one that exited non-zero: the console kernel
 * reports an uncaught command exception after `CommandFinished`), else
 * `unknown`. Laravel fires the command events outside unit tests only.
 *
 * Every step swallows its own exceptions: capture never reaches the host
 * application and never stops its reporting.
 */
final class ExceptionCollectorService
{
    public const MAX_PENDING_GROUPS = 100;

    /** @var array<string, array<string, mixed>> */
    private array $groups = [];

    /** @var WeakMap<Throwable, string> job class of exceptions thrown by a job */
    private WeakMap $jobNames;

    /** @var list<string> Artisan commands running in this process, innermost last */
    private array $commands = [];

    /**
     * The last command that exited non-zero. An uncaught command exception
     * is reported by the console kernel after `CommandFinished`.
     */
    private ?string $failedCommand = null;

    /**
     * @param  (Closure(): int)|null  $clock  current Unix time; tests pass a fixed one
     */
    public function __construct(
        private readonly Application $application,
        private readonly ExceptionConfigurationData $configuration,
        private readonly RuntimeBufferService $buffer,
        private readonly ?Closure $clock = null,
    ) {
        $this->jobNames = new WeakMap;
    }

    /**
     * Register the collector, its `reportable()` callback, the context
     * listeners and the flushes.
     */
    public static function register(Application $application, ExceptionConfigurationData $configuration): void
    {
        $collector = new self(
            $application,
            $configuration,
            new RuntimeBufferService($application->storagePath('excubia'.DIRECTORY_SEPARATOR.'exceptions'), $configuration->bufferMaxBytes),
        );
        $application->instance(self::class, $collector);

        $attach = static function (mixed $handler) use ($collector): void {
            if ($handler instanceof Handler) {
                $handler->reportable(static function (Throwable $exception) use ($collector): void {
                    $collector->capture($exception);
                });
            }
        };

        if ($application->resolved(ExceptionHandler::class)) {
            $attach($application->make(ExceptionHandler::class));
        } else {
            $application->afterResolving(ExceptionHandler::class, $attach);
        }

        /** @var Dispatcher $events */
        $events = $application->make('events');
        $events->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $event) => $collector->rememberJob($event));
        $events->listen(JobFailed::class, fn (JobFailed $event) => $collector->rememberJob($event));
        $events->listen(CommandStarting::class, fn (CommandStarting $event) => $collector->startCommand($event));
        $events->listen(CommandFinished::class, fn (CommandFinished $event) => $collector->finishCommand($event->exitCode));
        $events->listen(Looping::class, fn (Looping $event) => $collector->flush());

        $application->terminating(fn () => $collector->flush());
    }

    /**
     * Record one reported exception. Returns nothing on purpose: a
     * `reportable()` callback that returns false would stop the app's own
     * reporting.
     *
     * Failed mails are not counted here: the runtime `mail` type counts them
     * on its own (#175), so a reported transport exception is not counted twice.
     */
    public function capture(Throwable $exception): void
    {
        $this->guard(function () use ($exception): void {
            foreach ($this->configuration->ignoredClasses as $ignoredClass) {
                if ($exception instanceof $ignoredClass) {
                    return;
                }
            }

            $group = ExceptionPayloadService::group(
                $exception,
                $this->application->basePath(),
                $this->configuration->maskPatterns,
                $this->context($exception),
                (string) $this->application->environment(),
                gmdate('Y-m-d\TH:i:s\Z', $this->clock !== null ? ($this->clock)() : time()),
            );
            $key = ExceptionPayloadService::mergeKey($group);

            $this->groups[$key] = isset($this->groups[$key])
                ? ExceptionPayloadService::combine($this->groups[$key], $group)
                : $group;

            if (count($this->groups) >= self::MAX_PENDING_GROUPS) {
                $this->flush();
            }
        });
    }

    public function rememberJob(JobExceptionOccurred|JobFailed $event): void
    {
        $this->guard(function () use ($event): void {
            $this->jobNames[$event->exception] ??= $event->job->resolveName();
        });
    }

    public function startCommand(CommandStarting $event): void
    {
        $this->failedCommand = null;
        $this->commands[] = $event->command === '' ? 'unknown' : $event->command;
    }

    public function finishCommand(int $exitCode): void
    {
        $command = array_pop($this->commands);
        $this->failedCommand = $exitCode === 0 ? null : $command;
    }

    /**
     * Write the pending groups to the buffer and forget them.
     */
    public function flush(): void
    {
        $this->guard(function (): void {
            if ($this->groups === []) {
                return;
            }

            $groups = array_values($this->groups);
            $this->groups = [];
            $this->buffer->append($groups);
        });
    }

    /**
     * @return array{kind: string, name: string}
     */
    private function context(Throwable $exception): array
    {
        if (isset($this->jobNames[$exception])) {
            return ['kind' => 'job', 'name' => $this->jobNames[$exception]];
        }

        $request = $this->application->bound('request') ? $this->application->make('request') : null;
        $route = $request instanceof Request ? $request->route() : null;

        if ($route instanceof Route) {
            $routeName = $route->getName();

            return ['kind' => 'request', 'name' => is_string($routeName) && $routeName !== '' ? $routeName : 'unnamed'];
        }

        $command = $this->commands === [] ? $this->failedCommand : $this->commands[array_key_last($this->commands)];

        if ($command !== null) {
            return ['kind' => 'command', 'name' => $command];
        }

        if (! $this->application->runningInConsole()) {
            return ['kind' => 'request', 'name' => 'unnamed'];
        }

        return ['kind' => 'unknown', 'name' => 'unknown'];
    }

    /**
     * Run capture code; any exception stays inside Excubia.
     *
     * ponytail: errors are dropped, not logged: logging from inside the
     * exception handler could loop back into it. A debug log is the upgrade path.
     */
    private function guard(Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // Capture never reaches the host application.
        }
    }
}
