<?php

declare(strict_types=1);

namespace Qiick\Excubia\Contracts;

/**
 * Reads the OPcache status and the production `php.ini` directives of the
 * running PHP process, for `php-opcache` and `php-production-ini` (#172).
 * Tests fake it; `PhpRuntimeService` is the real one.
 */
interface PhpRuntimeContract
{
    /**
     * Booleans, percentages and a count only: never an ini file path, a
     * script path or any other value.
     *
     * @return array{isOpcacheEnabled: bool, hitRate: float|null, memoryUsedPercent: float|null, cachedScripts: int|null, isValidateTimestampsEnabled: bool, isDisplayErrorsEnabled: bool, isExposePhpEnabled: bool, isLogErrorsEnabled: bool}
     */
    public function read(): array;
}
