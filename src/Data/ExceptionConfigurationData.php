<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * The `exceptions` key of `excubia.json`: opt-in exception capture by the
 * Laravel provider, sent with `excubia:push-runtime` (`docs/exception-schema.json`).
 *
 * Off by default and independent of `runtime.enabled`. When `isEnabled` is
 * false the provider registers nothing on the exception handler and no
 * listener. `maskPatterns` are extra PCRE patterns (with delimiters) whose
 * matches in exception messages become `[masked]`, applied before the
 * built-in masking. `ignoredClasses` are exception classes (and their
 * subclasses) that are never captured. `bufferMaxBytes` caps the local
 * buffer under `storage/excubia/exceptions/`; the oldest entries are dropped
 * first.
 */
final readonly class ExceptionConfigurationData
{
    public const DEFAULT_BUFFER_MAX_BYTES = 1048576;

    /**
     * @param  list<string>  $maskPatterns
     * @param  list<string>  $ignoredClasses
     *
     * @throws InvalidConfigurationException
     */
    public function __construct(
        public bool $isEnabled = false,
        public array $maskPatterns = [],
        public array $ignoredClasses = [],
        public int $bufferMaxBytes = self::DEFAULT_BUFFER_MAX_BYTES,
    ) {
        if ($this->bufferMaxBytes < RuntimeConfigurationData::MINIMUM_BUFFER_MAX_BYTES) {
            throw new InvalidConfigurationException('exceptions.bufferMaxBytes must be at least '.RuntimeConfigurationData::MINIMUM_BUFFER_MAX_BYTES.'.');
        }

        foreach ($this->maskPatterns as $index => $pattern) {
            // An invalid pattern raises a warning and returns false; keep only the false.
            set_error_handler(static fn (): bool => true);

            try {
                $isValid = preg_match($pattern, '') !== false;
            } finally {
                restore_error_handler();
            }

            if (! $isValid) {
                throw new InvalidConfigurationException('exceptions.maskPatterns['.$index.'] is not a valid regular expression.');
            }
        }
    }
}
