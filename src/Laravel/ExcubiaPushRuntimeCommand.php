<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Contracts\DiskSpaceContract;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Contracts\RuntimeSamplerContract;
use Qiick\Excubia\Contracts\ServerResourcesContract;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Data\RuntimeConfigurationData;
use Qiick\Excubia\Enums\ExitCodeEnum;
use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Services\DiskSpaceService;
use Qiick\Excubia\Services\HealthCheckRunnerService;
use Qiick\Excubia\Services\HealthSampleService;
use Qiick\Excubia\Services\ReleaseService;
use Qiick\Excubia\Services\ReportPushService;
use Qiick\Excubia\Services\RuntimeBufferService;
use Qiick\Excubia\Services\ServerMetricsService;
use Qiick\Excubia\Services\ServerResourcesService;
use Qiick\Excubia\Services\SymfonyHttpClientService;
use Throwable;

/**
 * `php artisan excubia:push-runtime`: send the buffered runtime aggregates
 * to the user's own `excubia-app` (`POST {url}/api/runtime`,
 * `docs/runtime-schema.json`) and the buffered exception groups
 * (`POST {url}/api/exceptions`, `docs/exception-schema.json`). Each buffer
 * is sent only when its opt-in is on (`runtime.enabled`,
 * `exceptions.enabled`); the provider schedules the command every minute
 * when either is.
 *
 * Batches are sent oldest first and deleted only after a 2xx answer. On the
 * first failure the run stops and keeps that batch and every later one; the
 * next run retries them with the same `batchId`, so the app stores each once.
 * The buffer cap still applies to kept batches.
 *
 * A failure on one buffer does not stop the other one.
 *
 * Exit codes: 0 sent (or nothing to send, or both opt-ins disabled),
 * 2 configuration error, 3 the app rejected the token, answered an error or
 * could not be reached. `EXCUBIA_APP_URL` / `EXCUBIA_APP_TOKEN` win over the
 * `app` keys of `excubia.json`, as for `excubia:push`.
 *
 * When `runtime.server` is on (#149), each run first appends one `server`
 * sample of this machine to the runtime buffer, so it goes out in the same
 * run. The scheduler runs the command on every server, so each server
 * pushes its own sample, named by `EXCUBIA_SERVER_NAME` (#162), else
 * `runtime.server.name`, else a hashed id; an invalid variable is warned
 * about on stderr and the hashed id is sent.
 *
 * When `runtime.health` is on (#188), each run then, at most once per UTC
 * minute (the last sampled minute is kept in `health-minute` next to the
 * buffer), runs the checks of `runtime.health.checks` one at a time, with
 * only the probes each one reads, and appends one `health` aggregate per
 * check (HealthSampleService). The checks only probe local services; no
 * data source is called. A check that throws, or that would start after
 * the time budget, is sent as `skipped`; the push always goes on.
 *
 * Each batch carries the release resolved when it is sent (`release` key,
 * `EXCUBIA_RELEASE` or a platform variable; no git fallback here), and
 * each runtime batch the Laravel environment (`app.env`, #203).
 *
 * ponytail: the release is the one at send time, not at record time, so a
 * batch recorded in the minute before a deploy, or kept after a failure, is
 * labelled with the new release; record it per buffered line if the app
 * ever needs that precision.
 *
 * ponytail: a batch the app keeps rejecting with a 4xx stays until the cap
 * drops it; drop it on a permanent 4xx if that ever blocks real data.
 */
final class ExcubiaPushRuntimeCommand extends Command
{
    protected $signature = 'excubia:push-runtime
        {--config= : Config file}';

    protected $description = 'Send the buffered runtime aggregates and exceptions to your excubia-app.';

    public function handle(): int
    {
        $startedAtMs = self::clockMs();
        $config = $this->option('config');
        $configPath = is_string($config) && $config !== '' ? $config : null;

        try {
            $configuration = (new ExcubiaConfigurationLoader)->load($configPath, ExcubiaCheckCommand::applicationBasePath($this->getLaravel()));
        } catch (InvalidConfigurationException $exception) {
            $this->error($exception->getMessage());

            return ExitCodeEnum::UsageOrConfigurationError->value;
        }

        CheckCommand::warnAboutTokenInFile($configuration, $this->output);

        if (! $configuration->runtime->isEnabled && ! $configuration->exceptions->isEnabled) {
            $this->line('Runtime monitoring is disabled (runtime.enabled in excubia.json), and so is exception capture (exceptions.enabled).');

            return self::SUCCESS;
        }

        $pushService = new ReportPushService($this->httpClient());
        $appUrl = self::environmentValue('EXCUBIA_APP_URL') ?? $configuration->appUrl;
        $appToken = self::environmentValue('EXCUBIA_APP_TOKEN') ?? $configuration->appToken;
        $sentAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $release = (new ReleaseService)->resolve($configuration->release);
        $environment = $this->laravel->environment();
        $hasFailed = false;

        try {
            if ($configuration->runtime->isEnabled) {
                $runtimeBuffer = new RuntimeBufferService($this->laravel->storagePath('excubia'.DIRECTORY_SEPARATOR.'runtime'), $configuration->runtime->bufferMaxBytes);
                $this->warnAboutInvalidServerName($configuration->runtime);
                $this->sampleServer($configuration->runtime, $runtimeBuffer, $sentAt->getTimestamp());
                $this->sampleHealth($configuration, $runtimeBuffer, $sentAt, $startedAtMs);
                $hasFailed = ! $this->sendBuffer(
                    $runtimeBuffer,
                    static fn (RuntimeBufferService $buffer, string $path): array => $buffer->read($path),
                    static fn (array $entries, string $batchId) => $pushService->pushRuntime($entries, $batchId, $appUrl, $appToken, $sentAt, $release, $environment),
                    ['runtime batch', 'runtime batches', 'runtime data'],
                );
            }

            if ($configuration->exceptions->isEnabled) {
                $hasFailed = ! $this->sendBuffer(
                    new RuntimeBufferService($this->laravel->storagePath('excubia'.DIRECTORY_SEPARATOR.'exceptions'), $configuration->exceptions->bufferMaxBytes),
                    static fn (RuntimeBufferService $buffer, string $path): array => ExceptionPayloadService::merge($buffer->readEntries($path)),
                    static fn (array $entries, string $batchId) => $pushService->pushExceptions($entries, $batchId, $appUrl, $appToken, $sentAt, $release),
                    ['exception batch', 'exception batches', 'exception data'],
                ) || $hasFailed;
            }
        } catch (InvalidConfigurationException $exception) {
            $this->error($exception->getMessage());

            return ExitCodeEnum::UsageOrConfigurationError->value;
        }

        return $hasFailed ? ExitCodeEnum::DataSourceFailure->value : self::SUCCESS;
    }

    /**
     * Send every claimed batch of one buffer, oldest first, and delete each
     * after a 2xx. On the first failure this buffer stops and keeps that
     * batch and every later one; the other buffer is still sent.
     *
     * @param  Closure(RuntimeBufferService, string): list<array<string, mixed>>  $read
     * @param  Closure(list<array<string, mixed>>, string): void  $send
     * @param  array{0: string, 1: string, 2: string}  $nouns  singular, plural, data
     * @return bool false when the app rejected, failed or could not be reached
     *
     * @throws InvalidConfigurationException
     */
    private function sendBuffer(RuntimeBufferService $buffer, Closure $read, Closure $send, array $nouns): bool
    {
        $sentCount = 0;

        try {
            foreach ($buffer->claimBatches() as $batch) {
                $entries = $read($buffer, $batch['path']);

                if ($entries !== []) {
                    $send($entries, $batch['batchId']);
                    $sentCount++;
                }

                $buffer->delete($batch['path']);
            }
        } catch (DataSourceUnavailableException $exception) {
            $this->error($exception->getMessage());
            $this->error('The '.$nouns[2].' is kept for the next run.');

            return false;
        }

        $this->line($sentCount === 0 ? 'No '.$nouns[2].' to send.' : 'Sent '.$sentCount.' '.($sentCount === 1 ? $nouns[0] : $nouns[1]).' to excubia-app.');

        return true;
    }

    /**
     * Warn on stderr once per run when `EXCUBIA_SERVER_NAME` is invalid and
     * a type that names the server (`server`, `health`) is on.
     */
    private function warnAboutInvalidServerName(RuntimeConfigurationData $runtime): void
    {
        if ($runtime->isServerNameVariableInvalid && ($runtime->isTypeEnabled(RuntimeTypeEnum::Server) || $runtime->isTypeEnabled(RuntimeTypeEnum::Health))) {
            CheckCommand::writeError($this->output, RuntimeConfigurationData::SERVER_NAME_VARIABLE.' must be 1 to 64 letters, digits, dots, underscores or hyphens; the server is sent as its hashed id.');
        }
    }

    /**
     * Append one `server` sample when the type is on and sampled. The disk
     * is the one that holds `storage/`. A failure here never stops the push:
     * monitoring must not break the command.
     */
    private function sampleServer(RuntimeConfigurationData $runtime, RuntimeBufferService $buffer, int $timestamp): void
    {
        $sampleRate = $runtime->sampleRate(RuntimeTypeEnum::Server);

        if (! $runtime->isTypeEnabled(RuntimeTypeEnum::Server) || ! $this->isSampled($sampleRate)) {
            return;
        }

        try {
            $sampler = new ServerMetricsService(
                $this->bound(ServerResourcesContract::class) ?? new ServerResourcesService,
                $this->diskSpace(),
            );
            $buffer->append([$sampler->aggregate($this->serverIdentity($runtime), $this->laravel->storagePath(), $sampleRate, $timestamp)]);
        } catch (Throwable) {
            // The server sample is best effort; the buffered data is still sent.
        }
    }

    /**
     * Append one `health` aggregate per configured check when the type is
     * on, sampled, and this minute has no sample yet. Each check gets a
     * posture with only its own probes, so the time of a slow probe is
     * charged to its check. A failure here never stops the push.
     */
    private function sampleHealth(ExcubiaConfigurationData $configuration, RuntimeBufferService $buffer, DateTimeImmutable $now, float $startedAtMs): void
    {
        $runtime = $configuration->runtime;
        $sampleRate = $runtime->sampleRate(RuntimeTypeEnum::Health);
        $minute = gmdate('Y-m-d\TH:i', $now->getTimestamp());
        $minutePath = $this->laravel->storagePath('excubia'.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'health-minute');

        if (! $runtime->isTypeEnabled(RuntimeTypeEnum::Health) || (is_file($minutePath) && file_get_contents($minutePath) === $minute) || ! $this->isSampled($sampleRate)) {
            return;
        }

        try {
            $posture = new LaravelPostureService($this->diskSpace());
            $runner = new HealthCheckRunnerService($this->httpClient(), $now);
            $snapshot = new ProjectSnapshotData(null, null, [], [], null);
            $sampler = new HealthSampleService($runtime->healthCheckBudgetMs, $runtime->healthTotalBudgetMs, self::clockMs(...));
            $buffer->append($sampler->aggregates(
                $runtime->healthCheckIds,
                fn (string $checkId) => $runner->runCheck($snapshot->withLaravelPosture($posture->collect($this->getLaravel(), $now, $configuration, [$checkId])), $configuration, $checkId),
                $startedAtMs,
                $this->serverIdentity($runtime),
                $sampleRate,
                $now->getTimestamp(),
            ));
            file_put_contents($minutePath, $minute);
        } catch (Throwable) {
            // The health sample is best effort; the buffered data is still sent.
        }
    }

    /**
     * The server name of `server` and `health` aggregates: the configured
     * name, else a hash of the hostname and the app key, never the hostname.
     */
    private function serverIdentity(RuntimeConfigurationData $runtime): string
    {
        $applicationKey = $this->laravel->make('config')->get('app.key');
        $hostname = gethostname();

        return ServerMetricsService::serverIdentity($runtime->serverName, is_string($hostname) ? $hostname : '', is_string($applicationKey) ? $applicationKey : '');
    }

    private function diskSpace(): DiskSpaceContract
    {
        return $this->bound(DiskSpaceContract::class) ?? new DiskSpaceService;
    }

    /**
     * True for a run that takes the sample: always at rate 1, never at 0,
     * else the container's `RuntimeSamplerContract` draws.
     */
    private function isSampled(float $sampleRate): bool
    {
        if ($sampleRate >= 1.0) {
            return true;
        }

        return $sampleRate > 0.0 && $this->laravel->make(RuntimeSamplerContract::class)->isSampled($sampleRate);
    }

    private static function clockMs(): float
    {
        return hrtime(true) / 1_000_000;
    }

    /**
     * The container binding when there is one (tests bind a fake), else the
     * Symfony client with the push timeouts and retries.
     */
    private function httpClient(): HttpClientContract
    {
        return $this->bound(HttpClientContract::class) ?? new SymfonyHttpClientService;
    }

    /**
     * The container binding of an interface, when there is one of that type.
     *
     * @template TContract of object
     *
     * @param  class-string<TContract>  $contract
     * @return TContract|null
     */
    private function bound(string $contract): ?object
    {
        if (! $this->laravel->bound($contract)) {
            return null;
        }

        $instance = $this->laravel->make($contract);

        return $instance instanceof $contract ? $instance : null;
    }

    private static function environmentValue(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
