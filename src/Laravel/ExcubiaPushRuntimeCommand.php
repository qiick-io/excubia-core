<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Enums\ExitCodeEnum;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Services\ReportPushService;
use Qiick\Excubia\Services\RuntimeBufferService;
use Qiick\Excubia\Services\SymfonyHttpClientService;

/**
 * `php artisan excubia:push-runtime`: send the buffered runtime aggregates
 * to the user's own `excubia-app` (`POST {url}/api/runtime`,
 * `docs/runtime-schema.json`). The provider schedules it every minute when
 * `runtime.enabled` is true.
 *
 * Batches are sent oldest first and deleted only after a 2xx answer. On the
 * first failure the run stops and keeps that batch and every later one; the
 * next run retries them with the same `batchId`, so the app stores each once.
 * The buffer cap still applies to kept batches.
 *
 * Exit codes: 0 sent (or nothing to send, or runtime monitoring disabled),
 * 2 configuration error, 3 the app rejected the token, answered an error or
 * could not be reached. `EXCUBIA_APP_URL` / `EXCUBIA_APP_TOKEN` win over the
 * `app` keys of `excubia.json`, as for `excubia:push`.
 *
 * ponytail: a batch the app keeps rejecting with a 4xx stays until the cap
 * drops it; drop it on a permanent 4xx if that ever blocks real data.
 */
final class ExcubiaPushRuntimeCommand extends Command
{
    protected $signature = 'excubia:push-runtime
        {--config= : Config file}';

    protected $description = 'Send the buffered runtime aggregates to your excubia-app.';

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

        if (! $configuration->runtime->isEnabled) {
            $this->line('Runtime monitoring is disabled (runtime.enabled in excubia.json).');

            return self::SUCCESS;
        }

        $buffer = new RuntimeBufferService($this->laravel->storagePath('excubia'.DIRECTORY_SEPARATOR.'runtime'), $configuration->runtime->bufferMaxBytes);
        $pushService = new ReportPushService($this->httpClient());
        $appUrl = self::environmentValue('EXCUBIA_APP_URL') ?? $configuration->appUrl;
        $appToken = self::environmentValue('EXCUBIA_APP_TOKEN') ?? $configuration->appToken;
        $sentCount = 0;

        try {
            foreach ($buffer->claimBatches() as $batch) {
                $aggregates = $buffer->read($batch['path']);

                if ($aggregates !== []) {
                    $pushService->pushRuntime($aggregates, $batch['batchId'], $appUrl, $appToken, new DateTimeImmutable('now', new DateTimeZone('UTC')));
                    $sentCount++;
                }

                $buffer->delete($batch['path']);
            }
        } catch (InvalidConfigurationException $exception) {
            $this->error($exception->getMessage());

            return ExitCodeEnum::UsageOrConfigurationError->value;
        } catch (DataSourceUnavailableException $exception) {
            $this->error($exception->getMessage());
            $this->error('The runtime data is kept for the next run.');

            return ExitCodeEnum::DataSourceFailure->value;
        }

        $this->line($sentCount === 0 ? 'No runtime data to send.' : 'Sent '.$sentCount.' runtime batch'.($sentCount === 1 ? '' : 'es').' to excubia-app.');

        return self::SUCCESS;
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
