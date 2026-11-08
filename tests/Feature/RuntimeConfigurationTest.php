<?php

declare(strict_types=1);

use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Data\RuntimeConfigurationData;
use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

it('keeps runtime monitoring off by default with every type on and full sampling', function () {
    $runtime = (new ExcubiaConfigurationLoader)->load(null, temporaryProject([]))->runtime;

    expect($runtime->isEnabled)->toBeFalse()
        ->and($runtime->bufferMaxBytes)->toBe(1048576)
        ->and($runtime->slowQueryThresholdMs)->toBe(1000);

    foreach (RuntimeTypeEnum::cases() as $type) {
        expect($runtime->isTypeEnabled($type))->toBeFalse()
            ->and($runtime->sampleRate($type))->toBe(1.0);
    }
});

it('reads the runtime key with per-type switches, sample rates and the slow query threshold', function () {
    $runtime = (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => json_encode(['runtime' => [
            'enabled' => true,
            'bufferMaxBytes' => 65536,
            'requests' => ['sampleRate' => 0.25],
            'slowQueries' => ['thresholdMs' => 250],
            'jobs' => ['enabled' => false],
            'scheduler' => ['sampleRate' => 1],
            'outgoingHttp' => ['enabled' => true, 'sampleRate' => 0],
        ]], JSON_THROW_ON_ERROR),
    ]))->runtime;

    expect($runtime->isEnabled)->toBeTrue()
        ->and($runtime->bufferMaxBytes)->toBe(65536)
        ->and($runtime->slowQueryThresholdMs)->toBe(250)
        ->and($runtime->isTypeEnabled(RuntimeTypeEnum::Request))->toBeTrue()
        ->and($runtime->sampleRate(RuntimeTypeEnum::Request))->toBe(0.25)
        ->and($runtime->isTypeEnabled(RuntimeTypeEnum::Job))->toBeFalse()
        ->and($runtime->isTypeEnabled(RuntimeTypeEnum::SlowQuery))->toBeTrue()
        ->and($runtime->sampleRate(RuntimeTypeEnum::ScheduledTask))->toBe(1.0)
        ->and($runtime->sampleRate(RuntimeTypeEnum::OutgoingHttp))->toBe(0.0);
});

it('keeps the types added in 1.0.0-beta.2 off by default and reads their switches and sample rates', function () {
    $load = static fn (array $runtime) => (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => json_encode(['runtime' => $runtime], JSON_THROW_ON_ERROR),
    ]))->runtime;
    $added = [RuntimeTypeEnum::RequestMemory, RuntimeTypeEnum::JobMemory, RuntimeTypeEnum::QueueWait, RuntimeTypeEnum::Cache, RuntimeTypeEnum::Mail, RuntimeTypeEnum::Notification, RuntimeTypeEnum::LogLevel, RuntimeTypeEnum::Deprecation, RuntimeTypeEnum::DuplicateQuery, RuntimeTypeEnum::Server, RuntimeTypeEnum::Command, RuntimeTypeEnum::Health, RuntimeTypeEnum::Ai, RuntimeTypeEnum::AiTool, RuntimeTypeEnum::Octane, RuntimeTypeEnum::Reverb, RuntimeTypeEnum::BillingWebhook];
    $defaults = $load(['enabled' => true]);

    foreach (RuntimeTypeEnum::cases() as $type) {
        expect($defaults->isTypeEnabled($type))->toBe(! in_array($type, $added, true));
    }

    $switched = $load(['enabled' => true, 'cache' => ['enabled' => true, 'sampleRate' => 0.1], 'logLevels' => ['enabled' => true], 'notifications' => ['enabled' => false]]);

    expect($switched->isTypeEnabled(RuntimeTypeEnum::Cache))->toBeTrue()
        ->and($switched->sampleRate(RuntimeTypeEnum::Cache))->toBe(0.1)
        ->and($switched->isTypeEnabled(RuntimeTypeEnum::LogLevel))->toBeTrue()
        ->and($switched->isTypeEnabled(RuntimeTypeEnum::Notification))->toBeFalse()
        ->and(fn () => $load(['cache' => ['thresholdMs' => 5]]))->toThrow(InvalidConfigurationException::class, 'Unknown runtime.cache key [thresholdMs].')
        ->and(fn () => $load(['logLevels' => ['sampleRate' => 2]]))->toThrow(InvalidConfigurationException::class, 'runtime.logLevels.sampleRate must be between 0 and 1.');

    $deprecations = $load(['enabled' => true, 'deprecations' => ['enabled' => true, 'sampleRate' => 0.5]]);

    expect($deprecations->isTypeEnabled(RuntimeTypeEnum::Deprecation))->toBeTrue()
        ->and($deprecations->sampleRate(RuntimeTypeEnum::Deprecation))->toBe(0.5)
        ->and($load(['deprecations' => ['enabled' => true]])->isTypeEnabled(RuntimeTypeEnum::Deprecation))->toBeFalse()
        ->and(fn () => $load(['deprecations' => ['thresholdMs' => 5]]))->toThrow(InvalidConfigurationException::class, 'Unknown runtime.deprecations key [thresholdMs].');
});

it('keeps the Laravel AI SDK types off by default and reads their switches and sample rates', function () {
    $load = static fn (array $runtime) => (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => json_encode(['runtime' => $runtime], JSON_THROW_ON_ERROR),
    ]))->runtime;
    $defaults = $load(['enabled' => true]);
    $switched = $load(['enabled' => true, 'ai' => ['enabled' => true, 'sampleRate' => 0.5], 'aiTools' => ['enabled' => true]]);

    expect($defaults->isTypeEnabled(RuntimeTypeEnum::Ai))->toBeFalse()
        ->and($defaults->isTypeEnabled(RuntimeTypeEnum::AiTool))->toBeFalse()
        ->and($switched->isTypeEnabled(RuntimeTypeEnum::Ai))->toBeTrue()
        ->and($switched->sampleRate(RuntimeTypeEnum::Ai))->toBe(0.5)
        ->and($switched->isTypeEnabled(RuntimeTypeEnum::AiTool))->toBeTrue()
        ->and($switched->sampleRate(RuntimeTypeEnum::AiTool))->toBe(1.0)
        ->and($load(['ai' => ['enabled' => true]])->isTypeEnabled(RuntimeTypeEnum::Ai))->toBeFalse()
        ->and(fn () => $load(['ai' => ['threshold' => 5]]))->toThrow(InvalidConfigurationException::class, 'Unknown runtime.ai key [threshold].')
        ->and(fn () => $load(['aiTools' => ['sampleRate' => 2]]))->toThrow(InvalidConfigurationException::class, 'runtime.aiTools.sampleRate must be between 0 and 1.');
});

it('keeps duplicate query detection off by default and reads its threshold', function () {
    $load = static fn (array $runtime) => (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => json_encode(['runtime' => $runtime], JSON_THROW_ON_ERROR),
    ]))->runtime;
    $defaults = $load(['enabled' => true]);
    $switched = $load(['enabled' => true, 'duplicateQueries' => ['enabled' => true, 'sampleRate' => 0.5, 'threshold' => 3]]);

    expect($defaults->isTypeEnabled(RuntimeTypeEnum::DuplicateQuery))->toBeFalse()
        ->and($defaults->duplicateQueryThreshold)->toBe(5)
        ->and($switched->isTypeEnabled(RuntimeTypeEnum::DuplicateQuery))->toBeTrue()
        ->and($switched->sampleRate(RuntimeTypeEnum::DuplicateQuery))->toBe(0.5)
        ->and($switched->duplicateQueryThreshold)->toBe(3)
        ->and(fn () => $load(['duplicateQueries' => ['threshold' => 1]]))->toThrow(InvalidConfigurationException::class, 'runtime.duplicateQueries.threshold must be at least 2.')
        ->and(fn () => $load(['duplicateQueries' => ['threshold' => '5']]))->toThrow(InvalidConfigurationException::class, 'runtime.duplicateQueries.threshold must be an integer.')
        ->and(fn () => $load(['duplicateQueries' => ['thresholdMs' => 5]]))->toThrow(InvalidConfigurationException::class, 'Unknown runtime.duplicateQueries key [thresholdMs].')
        ->and(fn () => $load(['slowQueries' => ['threshold' => 5]]))->toThrow(InvalidConfigurationException::class, 'Unknown runtime.slowQueries key [threshold].');
});

it('keeps server metrics off by default and reads the server name', function () {
    $load = static fn (array $runtime) => (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => json_encode(['runtime' => $runtime], JSON_THROW_ON_ERROR),
    ]))->runtime;
    $defaults = $load(['enabled' => true]);
    $switched = $load(['enabled' => true, 'server' => ['enabled' => true, 'sampleRate' => 0.5, 'name' => 'web-1.eu_west']]);

    expect($defaults->isTypeEnabled(RuntimeTypeEnum::Server))->toBeFalse()
        ->and($defaults->serverName)->toBeNull()
        ->and($switched->isTypeEnabled(RuntimeTypeEnum::Server))->toBeTrue()
        ->and($switched->sampleRate(RuntimeTypeEnum::Server))->toBe(0.5)
        ->and($switched->serverName)->toBe('web-1.eu_west')
        ->and(fn () => $load(['server' => ['name' => 7]]))->toThrow(InvalidConfigurationException::class, 'runtime.server.name must be a string.')
        ->and(fn () => $load(['server' => ['name' => '']]))->toThrow(InvalidConfigurationException::class, 'runtime.server.name must be 1 to 64 letters, digits, dots, underscores or hyphens.')
        ->and(fn () => $load(['server' => ['name' => 'web 1']]))->toThrow(InvalidConfigurationException::class, 'runtime.server.name must be 1 to 64 letters, digits, dots, underscores or hyphens.')
        ->and(fn () => $load(['server' => ['name' => str_repeat('a', 65)]]))->toThrow(InvalidConfigurationException::class, 'runtime.server.name must be 1 to 64 letters, digits, dots, underscores or hyphens.')
        ->and(fn () => $load(['server' => ['threshold' => 5]]))->toThrow(InvalidConfigurationException::class, 'Unknown runtime.server key [threshold].')
        ->and(fn () => $load(['jobs' => ['name' => 'web-1']]))->toThrow(InvalidConfigurationException::class, 'Unknown runtime.jobs key [name].');
});

it('keeps command runs off by default and reads their ignore list', function () {
    $load = static fn (array $runtime) => (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => json_encode(['runtime' => $runtime], JSON_THROW_ON_ERROR),
    ]))->runtime;
    $defaults = $load(['enabled' => true]);
    $switched = $load(['enabled' => true, 'commands' => ['enabled' => true, 'sampleRate' => 0.5, 'ignore' => ['app:*', 'migrate']]]);

    expect($defaults->isTypeEnabled(RuntimeTypeEnum::Command))->toBeFalse()
        ->and($defaults->ignoredCommands)->toBe(['schedule:run', 'schedule:work', 'queue:work', 'queue:listen', 'horizon*', 'tinker'])
        ->and($switched->isTypeEnabled(RuntimeTypeEnum::Command))->toBeTrue()
        ->and($switched->sampleRate(RuntimeTypeEnum::Command))->toBe(0.5)
        ->and($switched->ignoredCommands)->toBe(['app:*', 'migrate'])
        ->and($load(['commands' => ['ignore' => []]])->ignoredCommands)->toBe([])
        ->and(fn () => $load(['commands' => ['ignore' => 'tinker']]))->toThrow(InvalidConfigurationException::class, 'runtime.commands.ignore must be a list of strings.')
        ->and(fn () => $load(['commands' => ['ignore' => ['']]]))->toThrow(InvalidConfigurationException::class, 'runtime.commands.ignore[0] must be a non-empty string.')
        ->and(fn () => $load(['commands' => ['threshold' => 5]]))->toThrow(InvalidConfigurationException::class, 'Unknown runtime.commands key [threshold].')
        ->and(fn () => $load(['jobs' => ['ignore' => []]]))->toThrow(InvalidConfigurationException::class, 'Unknown runtime.jobs key [ignore].');
});

it('takes the server name from EXCUBIA_SERVER_NAME before runtime.server.name, and falls back to the hashed id when it is invalid', function (array $environment, array $server, ?string $expectedName, bool $isInvalid) {
    $runtime = (new ExcubiaConfigurationLoader($environment))->load(null, temporaryProject([
        'excubia.json' => json_encode(['runtime' => ['enabled' => true, 'server' => ['enabled' => true, ...$server]]], JSON_THROW_ON_ERROR),
    ]))->runtime;

    expect($runtime->serverName)->toBe($expectedName)
        ->and($runtime->isServerNameVariableInvalid)->toBe($isInvalid);
})->with([
    'variable wins over the key' => [['EXCUBIA_SERVER_NAME' => 'web-2'], ['name' => 'web-1'], 'web-2', false],
    'variable without a key' => [['EXCUBIA_SERVER_NAME' => 'worker.eu_1'], [], 'worker.eu_1', false],
    'empty variable is unset' => [['EXCUBIA_SERVER_NAME' => ''], ['name' => 'web-1'], 'web-1', false],
    'no variable, key' => [[], ['name' => 'web-1'], 'web-1', false],
    'no variable, no key' => [[], [], null, false],
    'invalid variable, hashed id' => [['EXCUBIA_SERVER_NAME' => 'web 1'], ['name' => 'web-1'], null, true],
    'too long variable, hashed id' => [['EXCUBIA_SERVER_NAME' => str_repeat('a', 65)], [], null, true],
]);

it('reads EXCUBIA_SERVER_NAME from the process environment by default', function () {
    putenv('EXCUBIA_SERVER_NAME=web-from-env');

    try {
        $runtime = (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
            'excubia.json' => json_encode(['runtime' => ['enabled' => true]], JSON_THROW_ON_ERROR),
        ]))->runtime;
    } finally {
        putenv('EXCUBIA_SERVER_NAME');
    }

    expect($runtime->serverName)->toBe('web-from-env');
});

it('keeps health samples off by default and reads their checks and budgets', function () {
    $load = static fn (array $runtime) => (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => json_encode(['runtime' => $runtime], JSON_THROW_ON_ERROR),
    ]))->runtime;
    $defaults = $load(['enabled' => true]);
    $switched = $load(['enabled' => true, 'health' => ['enabled' => true, 'checks' => ['laravel-queue-backlog', 'laravel-database'], 'checkBudgetMs' => 500, 'totalBudgetMs' => 2000]]);
    $budgetMessage = 'runtime.health.checkBudgetMs must be at least 1 and no more than runtime.health.totalBudgetMs.';

    expect($defaults->isTypeEnabled(RuntimeTypeEnum::Health))->toBeFalse()
        ->and($defaults->healthCheckIds)->toBe(RuntimeConfigurationData::HEALTH_CHECK_IDS)
        ->and($defaults->healthCheckBudgetMs)->toBe(3000)
        ->and($defaults->healthTotalBudgetMs)->toBe(15000)
        ->and($switched->isTypeEnabled(RuntimeTypeEnum::Health))->toBeTrue()
        ->and($switched->healthCheckIds)->toBe(['laravel-queue-backlog', 'laravel-database'])
        ->and($switched->healthCheckBudgetMs)->toBe(500)
        ->and($switched->healthTotalBudgetMs)->toBe(2000)
        ->and(fn () => $load(['health' => ['checks' => ['composer-advisories']]]))->toThrow(InvalidConfigurationException::class, 'runtime.health.checks takes only laravel-database, laravel-cache, laravel-storage, laravel-disk-space, laravel-queue-backlog, laravel-queue-workers, laravel-scheduler-heartbeat, laravel-redis-memory, laravel-maintenance-mode; [composer-advisories] is not one of them.')
        ->and(fn () => $load(['health' => ['checks' => ['laravel-cache', 'laravel-cache']]]))->toThrow(InvalidConfigurationException::class, 'runtime.health.checks lists [laravel-cache] more than once.')
        ->and(fn () => $load(['health' => ['checks' => 'laravel-cache']]))->toThrow(InvalidConfigurationException::class, 'runtime.health.checks must be a list of strings.')
        ->and(fn () => $load(['health' => ['checkBudgetMs' => 0]]))->toThrow(InvalidConfigurationException::class, $budgetMessage)
        ->and(fn () => $load(['health' => ['checkBudgetMs' => 4000, 'totalBudgetMs' => 3000]]))->toThrow(InvalidConfigurationException::class, $budgetMessage)
        ->and(fn () => $load(['health' => ['totalBudgetMs' => '15s']]))->toThrow(InvalidConfigurationException::class, 'runtime.health.totalBudgetMs must be an integer.')
        ->and(fn () => $load(['health' => ['name' => 'web-1']]))->toThrow(InvalidConfigurationException::class, 'Unknown runtime.health key [name].')
        ->and(fn () => $load(['server' => ['checks' => []]]))->toThrow(InvalidConfigurationException::class, 'Unknown runtime.server key [checks].');
});

it('rejects an invalid runtime key', function (string $runtime, string $message) {
    expect(fn () => (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => '{"runtime": '.$runtime.'}',
    ])))->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'not an object' => ['true', 'runtime must be an object.'],
    'unknown key' => ['{"url": "https://x"}', 'Unknown runtime key [url].'],
    'type not an object' => ['{"jobs": true}', 'runtime.jobs must be an object.'],
    'unknown type key' => ['{"jobs": {"thresholdMs": 5}}', 'Unknown runtime.jobs key [thresholdMs].'],
    'enabled not boolean' => ['{"enabled": 1}', 'runtime.enabled must be a boolean.'],
    'sample rate above 1' => ['{"requests": {"sampleRate": 1.5}}', 'runtime.requests.sampleRate must be between 0 and 1.'],
    'sample rate not a number' => ['{"requests": {"sampleRate": "all"}}', 'runtime.requests.sampleRate must be a number between 0 and 1.'],
    'negative threshold' => ['{"slowQueries": {"thresholdMs": -1}}', 'runtime.slowQueries.thresholdMs must be zero or greater.'],
    'tiny buffer' => ['{"bufferMaxBytes": 10}', 'runtime.bufferMaxBytes must be at least 4096.'],
]);

it('documents runtime in the schema with the loader keys', function () {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/excubia.schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $typeKeys = array_map(fn (RuntimeTypeEnum $type): string => $type->configurationKey(), RuntimeTypeEnum::cases());

    expect($schema['properties']['runtime']['additionalProperties'])->toBeFalse()
        ->and(array_keys($schema['properties']['runtime']['properties']))->toBe(['enabled', 'bufferMaxBytes', ...$typeKeys])
        ->and(array_keys($schema['properties']['runtime']['properties']['slowQueries']['properties']))->toBe(['enabled', 'sampleRate', 'thresholdMs'])
        ->and(array_keys($schema['$defs']['runtimeType']['properties']))->toBe(['enabled', 'sampleRate']);
});

it('documents the runtime envelope in push-schema.json and the payload in runtime-schema.json', function () {
    $docs = dirname(__DIR__, 2).'/docs/';
    $push = json_decode((string) file_get_contents($docs.'push-schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $runtime = json_decode((string) file_get_contents($docs.'runtime-schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $envelope = $push['$defs']['runtimeEnvelope'];
    $documentedTypes = array_map(fn (array $item): string => substr($item['$ref'], strlen('#/$defs/')), $runtime['properties']['aggregates']['items']['oneOf']);

    expect($envelope['properties']['protocolVersion']['const'])->toBe(1)
        ->and($envelope['properties']['type']['const'])->toBe('runtime')
        ->and($envelope['required'])->toBe(['protocolVersion', 'type', 'batchId', 'sentAt', 'runtime'])
        ->and($envelope['properties']['runtime']['$ref'])->toEndWith('docs/runtime-schema.json')
        ->and($documentedTypes)->toBe(array_map(fn (RuntimeTypeEnum $type): string => $type->value, RuntimeTypeEnum::cases()));
});
