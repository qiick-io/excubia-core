<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Enums\ExitCodeEnum;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Services\ReleaseService;
use Qiick\Excubia\Services\ReportPushService;
use Qiick\Excubia\Services\RuntimeBufferService;
use Qiick\Excubia\Services\SymfonyHttpClientService;

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
 * Each batch carries the release resolved when it is sent (`release` key,
 * `EXCUBIA_RELEASE` or a platform variable; no git fallback here).
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
        $hasFailed = false;

        try {
            if ($configuration->runtime->isEnabled) {
                $hasFailed = ! $this->sendBuffer(
                    new RuntimeBufferService($this->laravel->storagePath('excubia'.DIRECTORY_SEPARATOR.'runtime'), $configuration->runtime->bufferMaxBytes),
                    static fn (RuntimeBufferService $buffer, string $path): array => $buffer->read($path),
                    static fn (array $entries, string $batchId) => $pushService->pushRuntime($entries, $batchId, $appUrl, $appToken, $sentAt, $release),
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
     * The container binding when there is one (tests bind a fake), else the
     * Symfony client with the push timeouts and retries.
     */
    private function httpClient(): HttpClientContract
    {
        if ($this->laravel->bound(HttpClientContract::class)) {
            $httpClient = $this->laravel->make(HttpClientContract::class);

            if ($httpClient instanceof HttpClientContract) {
                return $httpClient;
            }
        }

        return new SymfonyHttpClientService;
    }

    private static function environmentValue(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
