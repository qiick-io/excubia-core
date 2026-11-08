<?php

declare(strict_types=1);

/**
 * Package-only architecture rules. Naming, suffixes, strict types and readonly
 * DTOs are the shared qiick rules in NamingArchitectureTest.php (configured in
 * naming-architecture.php).
 */
arch('enums are enums')
    ->expect('Qiick\Excubia\Enums')
    ->toBeEnums();

arch('only src/Laravel uses illuminate, so the standalone CLI needs no Laravel runtime')
    ->expect('Qiick\Excubia')
    ->not->toUse('Illuminate')
    ->ignoring('Qiick\Excubia\Laravel');

arch('health checks only use contracts, data, enums, and services')
    ->expect('Qiick\Excubia\Checks')
    ->toOnlyUse([
        'Qiick\Excubia\Contracts',
        'Qiick\Excubia\Data',
        'Qiick\Excubia\Enums',
        'Qiick\Excubia\Services',
    ]);
