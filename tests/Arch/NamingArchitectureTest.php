<?php

declare(strict_types=1);

arch('uses strict types')
    ->expect('Qiick\Excubia')
    ->toUseStrictTypes();

arch('does not call debug functions or env')
    ->expect('Qiick\Excubia')
    ->not->toUse(['dd', 'dump', 'var_dump', 'env', 'ray']);

arch('data objects are readonly and suffixed')
    ->expect('Qiick\Excubia\Data')
    ->toBeReadonly()
    ->toHaveSuffix('Data');

arch('enums use the enum suffix')
    ->expect('Qiick\Excubia\Enums')
    ->toBeEnums()
    ->toHaveSuffix('Enum');

arch('contracts are interfaces')
    ->expect('Qiick\Excubia\Contracts')
    ->toBeInterfaces()
    ->toHaveSuffix('Contract');

arch('renderers use the renderer suffix')
    ->expect('Qiick\Excubia\Renderers')
    ->toHaveSuffix('Renderer');

arch('parsers use the parser suffix')
    ->expect('Qiick\Excubia\Parsers')
    ->toHaveSuffix('Parser');

arch('services use the service suffix')
    ->expect('Qiick\Excubia\Services')
    ->toHaveSuffix('Service');

arch('registries use the registry suffix')
    ->expect('Qiick\Excubia\Registries')
    ->toHaveSuffix('Registry');

arch('exceptions use the exception suffix')
    ->expect('Qiick\Excubia\Exceptions')
    ->toHaveSuffix('Exception');

arch('configuration loaders use the loader suffix')
    ->expect('Qiick\Excubia\Configuration')
    ->toHaveSuffix('Loader');

arch('console commands use the command suffix')
    ->expect('Qiick\Excubia\Console')
    ->toHaveSuffix('Command');

arch('health checks use the check suffix')
    ->expect('Qiick\Excubia\Checks')
    ->toHaveSuffix('Check');

arch('health checks only use contracts, data, enums, and services')
    ->expect('Qiick\Excubia\Checks')
    ->toOnlyUse([
        'Qiick\Excubia\Contracts',
        'Qiick\Excubia\Data',
        'Qiick\Excubia\Enums',
        'Qiick\Excubia\Services',
    ]);
