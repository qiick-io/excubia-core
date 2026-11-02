<?php

declare(strict_types=1);

namespace Qiick\Excubia\Configuration;

use Qiick\Excubia\Data\ExcubiaConfigurationData;
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
        $this->rejectUnknownKeys($document, ['$schema', 'failOn', 'includeDev', 'checks', 'ignore', 'cache'], 'excubia.json');
        $defaults = ExcubiaConfigurationData::defaults();

        if (array_key_exists('$schema', $document) && ! is_string($document['$schema'])) {
            throw new InvalidConfigurationException('$schema must be a string.');
        }

        $checks = $this->checks($document['checks'] ?? [], $defaults);
        $cache = $this->cache($document['cache'] ?? []);

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
        );
    }

    /**
     * @return array{
     *     staleAfterMonths: int,
     *     phpWarnDaysBeforeEndOfLife: int,
     *     laravelWarnDaysBeforeEndOfLife: int,
     *     isNodeEndOfLifeCheckEnabled: bool
     * }
     */
    private function checks(mixed $checks, ExcubiaConfigurationData $defaults): array
    {
        if (! is_array($checks)) {
            throw new InvalidConfigurationException('checks must be an object.');
        }

        $this->rejectUnknownKeys($checks, ['composer-stale', 'php-eol', 'laravel-eol', 'node-eol'], 'checks');

        return [
            'staleAfterMonths' => $this->integerSetting($checks, 'composer-stale', 'staleAfterMonths', $defaults->staleAfterMonths),
            'phpWarnDaysBeforeEndOfLife' => $this->integerSetting($checks, 'php-eol', 'warnDaysBeforeEol', $defaults->phpWarnDaysBeforeEndOfLife),
            'laravelWarnDaysBeforeEndOfLife' => $this->integerSetting($checks, 'laravel-eol', 'warnDaysBeforeEol', $defaults->laravelWarnDaysBeforeEndOfLife),
            'isNodeEndOfLifeCheckEnabled' => $this->booleanSetting($checks, 'node-eol', 'enabled', $defaults->isNodeEndOfLifeCheckEnabled),
        ];
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
