<?php

declare(strict_types=1);

namespace Qiick\Excubia\Configuration;

use Qiick\Excubia\Data\ExceptionConfigurationData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\RuntimeConfigurationData;
use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * Reads `excubia.json`. Unknown keys are a configuration error.
 *
 * A missing default file means the spec defaults. An explicit path that
 * does not exist is an error, because the caller asked for that file.
 * `advisoryId` is copied onto the ignore rule so the existing matcher can
 * target one advisory.
 */
final class ExcubiaConfigurationLoader
{
    /**
     * @throws InvalidConfigurationException
     */
    public function load(?string $configuredPath, string $projectRoot): ExcubiaConfigurationData
    {
        $path = $configuredPath ?? $projectRoot.DIRECTORY_SEPARATOR.'excubia.json';

        if ($configuredPath === null && ! is_file($path)) {
            return ExcubiaConfigurationData::defaults();
        }

        if (! is_file($path)) {
            throw new InvalidConfigurationException('Config file ['.$path.'] was not found.');
        }

        return $this->fromDocument($this->readObject($path));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function fromDocument(array $document): ExcubiaConfigurationData
    {
        $this->rejectUnknownKeys($document, ['$schema', 'failOn', 'includeDev', 'checks', 'ignore', 'cache', 'app', 'strict', 'healthEndpoint', 'runtime', 'exceptions', 'privacy'], 'excubia.json');
        $defaults = ExcubiaConfigurationData::defaults();

        if (array_key_exists('$schema', $document) && ! is_string($document['$schema'])) {
            throw new InvalidConfigurationException('$schema must be a string.');
        }

        $checks = $this->checks($document['checks'] ?? [], $defaults);
        $cache = $this->cache($document['cache'] ?? []);
        $app = $this->app($document['app'] ?? []);
        $healthEndpoint = $this->healthEndpoint($document['healthEndpoint'] ?? []);

        return new ExcubiaConfigurationData(
            failOnSeverity: array_key_exists('failOn', $document) ? $this->failOn($document['failOn']) : $defaults->failOnSeverity,
            isDevelopmentDependencyIncluded: array_key_exists('includeDev', $document) ? $this->boolean($document['includeDev'], 'includeDev') : $defaults->isDevelopmentDependencyIncluded,
            staleAfterMonths: $checks['staleAfterMonths'],
            phpWarnDaysBeforeEndOfLife: $checks['phpWarnDaysBeforeEndOfLife'],
            laravelWarnDaysBeforeEndOfLife: $checks['laravelWarnDaysBeforeEndOfLife'],
            isNodeEndOfLifeCheckEnabled: $checks['isNodeEndOfLifeCheckEnabled'],
            ignoreRules: $this->ignoreRules($document['ignore'] ?? []),
            cacheDirectory: $cache['directory'],
            cacheTtlHours: $cache['ttlHours'],
            appUrl: $app['url'],
            appToken: $app['token'],
            isStrict: array_key_exists('strict', $document) ? $this->boolean($document['strict'], 'strict') : $defaults->isStrict,
            isHealthEndpointEnabled: $healthEndpoint['enabled'],
            healthEndpointPath: $healthEndpoint['path'],
            schedulerHeartbeatStaleAfterMinutes: $checks['schedulerHeartbeatStaleAfterMinutes'],
            failedJobHighSeverityAfterHours: $checks['failedJobHighSeverityAfterHours'],
            runtime: $this->runtime($document['runtime'] ?? []),
            exceptions: $this->exceptions($document['exceptions'] ?? []),
            skippedPackagePatterns: $this->skippedPackagePatterns($document['privacy'] ?? []),
            databaseConnectionNames: $checks['databaseConnectionNames'],
            diskSpaceWarnAtPercentUsed: $checks['diskSpaceWarnAtPercentUsed'],
            diskSpaceFailAtPercentUsed: $checks['diskSpaceFailAtPercentUsed'],
            queueBacklogMaxOldestAgeMinutes: $checks['queueBacklogMaxOldestAgeMinutes'],
            queueBacklogMaxPendingJobs: $checks['queueBacklogMaxPendingJobs'],
            queueBacklogQueueNames: $checks['queueBacklogQueueNames'],
        );
    }

    /**
     * `privacy.skipPackages`: package name globs never sent to a public data
     * source. The glob syntax is checked by `ExcubiaConfigurationData`.
     *
     * @return list<string>
     *
     * @throws InvalidConfigurationException
     */
    private function skippedPackagePatterns(mixed $privacy): array
    {
        if (! is_array($privacy)) {
            throw new InvalidConfigurationException('privacy must be an object.');
        }

        $this->rejectUnknownKeys($privacy, ['skipPackages'], 'privacy');

        return $this->stringList($privacy['skipPackages'] ?? [], 'privacy.skipPackages');
    }

    /**
     * Opt-in exception capture (`docs/exception-schema.json`), independent of
     * `runtime`. The app URL and token are the shared `app` keys.
     *
     * @throws InvalidConfigurationException
     */
    private function exceptions(mixed $exceptions): ExceptionConfigurationData
    {
        if (! is_array($exceptions)) {
            throw new InvalidConfigurationException('exceptions must be an object.');
        }

        $this->rejectUnknownKeys($exceptions, ['enabled', 'maskPatterns', 'ignore', 'bufferMaxBytes'], 'exceptions');

        return new ExceptionConfigurationData(
            isEnabled: array_key_exists('enabled', $exceptions) ? $this->boolean($exceptions['enabled'], 'exceptions.enabled') : false,
            maskPatterns: $this->stringList($exceptions['maskPatterns'] ?? [], 'exceptions.maskPatterns'),
            ignoredClasses: array_map(static fn (string $class): string => ltrim($class, '\\'), $this->stringList($exceptions['ignore'] ?? [], 'exceptions.ignore')),
            bufferMaxBytes: array_key_exists('bufferMaxBytes', $exceptions) ? $this->integer($exceptions['bufferMaxBytes'], 'exceptions.bufferMaxBytes') : ExceptionConfigurationData::DEFAULT_BUFFER_MAX_BYTES,
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidConfigurationException($label.' must be a list of strings.');
        }

        foreach ($value as $index => $item) {
            $this->requiredString($item, $label.'['.$index.']');
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * Opt-in runtime aggregates (`docs/runtime-schema.json`). The app URL and
     * token are the `app` keys shared with `excubia push`.
     *
     * @throws InvalidConfigurationException
     */
    private function runtime(mixed $runtime): RuntimeConfigurationData
    {
        if (! is_array($runtime)) {
            throw new InvalidConfigurationException('runtime must be an object.');
        }

        $typeKeys = array_map(static fn (RuntimeTypeEnum $type): string => $type->configurationKey(), RuntimeTypeEnum::cases());
        $this->rejectUnknownKeys($runtime, ['enabled', 'bufferMaxBytes', ...$typeKeys], 'runtime');
        $enabledTypes = [];
        $sampleRates = [];
        $slowQueryThresholdMs = RuntimeConfigurationData::DEFAULT_SLOW_QUERY_THRESHOLD_MS;

        foreach (RuntimeTypeEnum::cases() as $type) {
            $label = 'runtime.'.$type->configurationKey();
            $settings = $runtime[$type->configurationKey()] ?? [];

            if (! is_array($settings)) {
                throw new InvalidConfigurationException($label.' must be an object.');
            }

            $this->rejectUnknownKeys($settings, $type === RuntimeTypeEnum::SlowQuery ? ['enabled', 'sampleRate', 'thresholdMs'] : ['enabled', 'sampleRate'], $label);

            if (array_key_exists('enabled', $settings)) {
                $enabledTypes[$type->value] = $this->boolean($settings['enabled'], $label.'.enabled');
            }

            if (array_key_exists('sampleRate', $settings)) {
                if (! is_int($settings['sampleRate']) && ! is_float($settings['sampleRate'])) {
                    throw new InvalidConfigurationException($label.'.sampleRate must be a number between 0 and 1.');
                }

                $sampleRates[$type->value] = (float) $settings['sampleRate'];
            }

            if (array_key_exists('thresholdMs', $settings)) {
                $slowQueryThresholdMs = $this->integer($settings['thresholdMs'], $label.'.thresholdMs');
            }
        }

        return new RuntimeConfigurationData(
            isEnabled: array_key_exists('enabled', $runtime) ? $this->boolean($runtime['enabled'], 'runtime.enabled') : false,
            enabledTypes: $enabledTypes,
            sampleRates: $sampleRates,
            slowQueryThresholdMs: $slowQueryThresholdMs,
            bufferMaxBytes: array_key_exists('bufferMaxBytes', $runtime) ? $this->integer($runtime['bufferMaxBytes'], 'runtime.bufferMaxBytes') : RuntimeConfigurationData::DEFAULT_BUFFER_MAX_BYTES,
        );
    }

    /**
     * Oh Dear-compatible health endpoint of the Laravel provider. The secret
     * is not a key here: it comes from `EXCUBIA_HEALTH_SECRET` only, so it is
     * never committed with the file.
     *
     * @return array{enabled: bool, path: string}
     */
    private function healthEndpoint(mixed $healthEndpoint): array
    {
        if (! is_array($healthEndpoint)) {
            throw new InvalidConfigurationException('healthEndpoint must be an object.');
        }

        $this->rejectUnknownKeys($healthEndpoint, ['enabled', 'path'], 'healthEndpoint');

        return [
            'enabled' => array_key_exists('enabled', $healthEndpoint) ? $this->boolean($healthEndpoint['enabled'], 'healthEndpoint.enabled') : false,
            'path' => array_key_exists('path', $healthEndpoint)
                ? trim($this->requiredString($healthEndpoint['path'], 'healthEndpoint.path'), '/')
                : ExcubiaConfigurationData::DEFAULT_HEALTH_ENDPOINT_PATH,
        ];
    }

    /**
     * `excubia push` target. Both keys are optional here; the push command
     * reports what is missing after the environment variables are applied.
     *
     * @return array{url: string|null, token: string|null}
     */
    private function app(mixed $app): array
    {
        if (! is_array($app)) {
            throw new InvalidConfigurationException('app must be an object.');
        }

        $this->rejectUnknownKeys($app, ['url', 'token'], 'app');

        return [
            'url' => array_key_exists('url', $app) ? $this->requiredString($app['url'], 'app.url') : null,
            'token' => array_key_exists('token', $app) ? $this->requiredString($app['token'], 'app.token') : null,
        ];
    }

    /**
     * @return array{
     *     staleAfterMonths: int,
     *     phpWarnDaysBeforeEndOfLife: int,
     *     laravelWarnDaysBeforeEndOfLife: int,
     *     isNodeEndOfLifeCheckEnabled: bool,
     *     schedulerHeartbeatStaleAfterMinutes: int,
     *     failedJobHighSeverityAfterHours: int,
     *     databaseConnectionNames: list<string>,
     *     diskSpaceWarnAtPercentUsed: int,
     *     diskSpaceFailAtPercentUsed: int,
     *     queueBacklogMaxOldestAgeMinutes: int,
     *     queueBacklogMaxPendingJobs: int,
     *     queueBacklogQueueNames: list<string>
     * }
     */
    private function checks(mixed $checks, ExcubiaConfigurationData $defaults): array
    {
        if (! is_array($checks)) {
            throw new InvalidConfigurationException('checks must be an object.');
        }

        $this->rejectUnknownKeys($checks, ['composer-stale', 'php-eol', 'laravel-eol', 'node-eol', 'laravel-scheduler-heartbeat', 'laravel-failed-jobs', 'laravel-database', 'laravel-disk-space', 'laravel-queue-backlog'], 'checks');
        $database = $this->checkObject($checks, 'laravel-database', ['connections']);
        $diskSpace = $this->checkObject($checks, 'laravel-disk-space', ['warnAtPercentUsed', 'failAtPercentUsed']);
        $queueBacklog = $this->checkObject($checks, 'laravel-queue-backlog', ['maxOldestAgeMinutes', 'maxPendingJobs', 'queues']);

        return [
            'staleAfterMonths' => $this->integerSetting($checks, 'composer-stale', 'staleAfterMonths', $defaults->staleAfterMonths),
            'phpWarnDaysBeforeEndOfLife' => $this->integerSetting($checks, 'php-eol', 'warnDaysBeforeEol', $defaults->phpWarnDaysBeforeEndOfLife),
            'laravelWarnDaysBeforeEndOfLife' => $this->integerSetting($checks, 'laravel-eol', 'warnDaysBeforeEol', $defaults->laravelWarnDaysBeforeEndOfLife),
            'isNodeEndOfLifeCheckEnabled' => $this->booleanSetting($checks, 'node-eol', 'enabled', $defaults->isNodeEndOfLifeCheckEnabled),
            'schedulerHeartbeatStaleAfterMinutes' => $this->integerSetting($checks, 'laravel-scheduler-heartbeat', 'staleAfterMinutes', $defaults->schedulerHeartbeatStaleAfterMinutes),
            'failedJobHighSeverityAfterHours' => $this->integerSetting($checks, 'laravel-failed-jobs', 'highSeverityAfterHours', $defaults->failedJobHighSeverityAfterHours),
            'databaseConnectionNames' => $this->stringList($database['connections'] ?? [], 'checks.laravel-database.connections'),
            'diskSpaceWarnAtPercentUsed' => array_key_exists('warnAtPercentUsed', $diskSpace) ? $this->integer($diskSpace['warnAtPercentUsed'], 'checks.laravel-disk-space.warnAtPercentUsed') : $defaults->diskSpaceWarnAtPercentUsed,
            'diskSpaceFailAtPercentUsed' => array_key_exists('failAtPercentUsed', $diskSpace) ? $this->integer($diskSpace['failAtPercentUsed'], 'checks.laravel-disk-space.failAtPercentUsed') : $defaults->diskSpaceFailAtPercentUsed,
            'queueBacklogMaxOldestAgeMinutes' => array_key_exists('maxOldestAgeMinutes', $queueBacklog) ? $this->integer($queueBacklog['maxOldestAgeMinutes'], 'checks.laravel-queue-backlog.maxOldestAgeMinutes') : $defaults->queueBacklogMaxOldestAgeMinutes,
            'queueBacklogMaxPendingJobs' => array_key_exists('maxPendingJobs', $queueBacklog) ? $this->integer($queueBacklog['maxPendingJobs'], 'checks.laravel-queue-backlog.maxPendingJobs') : $defaults->queueBacklogMaxPendingJobs,
            'queueBacklogQueueNames' => $this->stringList($queueBacklog['queues'] ?? [], 'checks.laravel-queue-backlog.queues'),
        ];
    }

    /**
     * The settings object of one check, empty when absent; keys outside
     * `$allowedKeys` are a configuration error.
     *
     * @param  array<mixed>  $checks
     * @param  list<string>  $allowedKeys
     * @return array<mixed>
     *
     * @throws InvalidConfigurationException
     */
    private function checkObject(array $checks, string $checkId, array $allowedKeys): array
    {
        $check = $checks[$checkId] ?? [];

        if (! is_array($check)) {
            throw new InvalidConfigurationException('checks.'.$checkId.' must be an object.');
        }

        $this->rejectUnknownKeys($check, $allowedKeys, 'checks.'.$checkId);

        return $check;
    }

    /**
     * @param  array<mixed>  $checks
     */
    private function integerSetting(array $checks, string $checkId, string $key, int $default): int
    {
        if (! array_key_exists($checkId, $checks)) {
            return $default;
        }

        $check = $checks[$checkId];

        if (! is_array($check)) {
            throw new InvalidConfigurationException('checks.'.$checkId.' must be an object.');
        }

        $this->rejectUnknownKeys($check, [$key], 'checks.'.$checkId);

        if (! array_key_exists($key, $check)) {
            return $default;
        }

        return $this->integer($check[$key], 'checks.'.$checkId.'.'.$key);
    }

    /**
     * @param  array<mixed>  $checks
     */
    private function booleanSetting(array $checks, string $checkId, string $key, bool $defaultValue): bool
    {
        if (! array_key_exists($checkId, $checks)) {
            return $defaultValue;
        }

        $check = $checks[$checkId];

        if (! is_array($check)) {
            throw new InvalidConfigurationException('checks.'.$checkId.' must be an object.');
        }

        $this->rejectUnknownKeys($check, [$key], 'checks.'.$checkId);

        if (! array_key_exists($key, $check)) {
            return $defaultValue;
        }

        return $this->boolean($check[$key], 'checks.'.$checkId.'.'.$key);
    }

    /**
     * @return array{directory: string|null, ttlHours: int}
     */
    private function cache(mixed $cache): array
    {
        $defaults = ExcubiaConfigurationData::defaults();

        if (! is_array($cache)) {
            throw new InvalidConfigurationException('cache must be an object.');
        }

        $this->rejectUnknownKeys($cache, ['directory', 'ttlHours'], 'cache');

        $directory = $defaults->cacheDirectory;

        if (array_key_exists('directory', $cache)) {
            if ($cache['directory'] !== null && ! is_string($cache['directory'])) {
                throw new InvalidConfigurationException('cache.directory must be a string or null.');
            }

            $directory = $cache['directory'];
        }

        return [
            'directory' => $directory,
            'ttlHours' => array_key_exists('ttlHours', $cache) ? $this->integer($cache['ttlHours'], 'cache.ttlHours') : $defaults->cacheTtlHours,
        ];
    }

    /**
     * @return list<array{checkId: string, subject: string, advisoryId: string|null, reason: string, until: string|null}>
     */
    private function ignoreRules(mixed $ignore): array
    {
        if (! is_array($ignore) || ! array_is_list($ignore)) {
            throw new InvalidConfigurationException('ignore must be a list.');
        }

        $rules = [];

        foreach ($ignore as $index => $rule) {
            if (! is_array($rule)) {
                throw new InvalidConfigurationException('ignore['.$index.'] must be an object.');
            }

            $this->rejectUnknownKeys($rule, ['checkId', 'subject', 'advisoryId', 'reason', 'until'], 'ignore['.$index.']');

            $advisoryId = $rule['advisoryId'] ?? null;

            if ($advisoryId !== null && ! is_string($advisoryId)) {
                throw new InvalidConfigurationException('ignore['.$index.'].advisoryId must be a string or null.');
            }

            $until = $rule['until'] ?? null;

            if ($until !== null && ! is_string($until)) {
                throw new InvalidConfigurationException('ignore['.$index.'].until must be a Y-m-d date or null.');
            }

            $rules[] = [
                'checkId' => $this->requiredString($rule['checkId'] ?? null, 'ignore['.$index.'].checkId'),
                'subject' => $this->requiredString($rule['subject'] ?? null, 'ignore['.$index.'].subject'),
                'advisoryId' => $advisoryId,
                'reason' => $this->requiredString($rule['reason'] ?? null, 'ignore['.$index.'].reason'),
                'until' => $until,
            ];
        }

        return $rules;
    }

    private function failOn(mixed $value): ?SeverityEnum
    {
        if ($value === 'none') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidConfigurationException('failOn must be critical, high, medium, low, or none.');
        }

        $severity = SeverityEnum::tryFrom($value);

        if ($severity === null) {
            throw new InvalidConfigurationException('failOn must be critical, high, medium, low, or none.');
        }

        return $severity;
    }

    private function boolean(mixed $value, string $label): bool
    {
        if (! is_bool($value)) {
            throw new InvalidConfigurationException($label.' must be a boolean.');
        }

        return $value;
    }

    private function integer(mixed $value, string $label): int
    {
        if (! is_int($value)) {
            throw new InvalidConfigurationException($label.' must be an integer.');
        }

        return $value;
    }

    private function requiredString(mixed $value, string $label): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidConfigurationException($label.' must be a non-empty string.');
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $document
     * @param  list<string>  $allowedKeys
     */
    private function rejectUnknownKeys(array $document, array $allowedKeys, string $label): void
    {
        foreach ($document as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                throw new InvalidConfigurationException('Unknown '.$label.' key ['.$key.'].');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readObject(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidConfigurationException('Config file ['.$path.'] could not be read.');
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidConfigurationException('Config file ['.$path.'] is not valid JSON.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new InvalidConfigurationException('Config file ['.$path.'] must be a JSON object.');
        }

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidConfigurationException('Config file ['.$path.'] must be a JSON object.');
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
