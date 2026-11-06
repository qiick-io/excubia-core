<?php

declare(strict_types=1);

use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Console\ClearCacheCommand;
use Qiick\Excubia\Console\PushCommand;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\HealthReportData;
use Qiick\Excubia\Enums\ExitCodeEnum;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Enums\SkipReasonEnum;
use Qiick\Excubia\Laravel\ExcubiaCheckCommand;
use Qiick\Excubia\Laravel\ExcubiaHeartbeatCommand;
use Qiick\Excubia\Laravel\ExcubiaPushCommand;
use Qiick\Excubia\Laravel\ExcubiaPushRuntimeCommand;
use Qiick\Excubia\Laravel\HealthEndpointService;
use Qiick\Excubia\Renderers\OhDearHealthRenderer;
use Qiick\Excubia\Renderers\SarifRenderer;
use Qiick\Excubia\Services\ReportPushService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/*
 * Snapshot of the public contracts frozen at 1.0.0-beta (#76,
 * docs/CONTRACTS.md). Each test compares what the code exposes today with
 * tests/Fixtures/Contracts/public-contracts.json, so no contract changes
 * without someone editing that file on purpose. `contractSnapshot()` and
 * `CONTRACT_CHANGE_MESSAGE` live in tests/Pest.php (shared with tests/Laravel).
 */

/**
 * Arguments and options of one command, as a user types them.
 *
 * @return array{arguments: array<string, string>, options: array<string, string>}
 */
function contractCommandDefinition(Command $command): array
{
    $arguments = [];
    $options = [];

    foreach ($command->getDefinition()->getArguments() as $argument) {
        $arguments[$argument->getName()] = $argument->isRequired() ? 'required' : 'optional';
    }

    foreach ($command->getDefinition()->getOptions() as $option) {
        $mode = 'flag';

        if ($option->acceptValue()) {
            $mode = $option->isValueRequired() ? 'value required' : 'value optional';
        }

        $options['--'.$option->getName()] = $mode;
    }

    return ['arguments' => $arguments, 'options' => $options];
}

/**
 * The SARIF log of a scan of a project with nothing locked, offline:
 * every check runs (pass or skipped), so the rules list every check id
 * in run order.
 *
 * @return array<string, mixed>
 */
function contractEmptyProjectSarif(): array
{
    $project = temporaryProject([
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [], "packages-dev": []}',
        'excubia.json' => json_encode(['cache' => ['directory' => temporaryProject([])]], JSON_THROW_ON_ERROR),
    ]);
    $tester = new CommandTester(new CheckCommand(new FakeHttpClient([]), new DateTimeImmutable('2026-11-03', new DateTimeZone('UTC'))));
    $tester->execute(['path' => $project, '--format' => 'sarif', '--offline' => true, '--no-banner' => true, '--fail-on' => 'none']);

    return json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Names under one top-level key of action.yml (`inputs` or `outputs`),
 * with the `default` of each input.
 *
 * @return array<string, string|null>
 */
function contractActionBlock(string $block): array
{
    $lines = explode("\n", (string) file_get_contents(dirname(__DIR__, 2).'/action.yml'));
    $entries = [];
    $isInside = false;
    $current = null;

    foreach ($lines as $line) {
        if (preg_match('/^[a-z]/', $line) === 1) {
            $isInside = $line === $block.':';

            continue;
        }

        if (! $isInside) {
            continue;
        }

        if (preg_match('/^  ([a-z0-9-]+):$/', $line, $match) === 1) {
            $current = $match[1];
            $entries[$current] = null;
        } elseif ($current !== null && preg_match('/^    default: (.*)$/', $line, $match) === 1) {
            $entries[$current] = trim($match[1], "'\"");
        }
    }

    return $entries;
}

it('versions every schema file and freezes its normalized content', function (): void {
    $root = dirname(__DIR__, 2);
    $schemas = [];

    foreach (['docs/report-schema.json', 'docs/push-schema.json', 'docs/runtime-schema.json', 'docs/exception-schema.json', 'excubia.schema.json'] as $file) {
        $schema = json_decode((string) file_get_contents($root.'/'.$file), true, 512, JSON_THROW_ON_ERROR);
        $schemas[$file] = [
            '$id' => $schema['$id'],
            'x-excubia-version' => $schema['x-excubia-version'],
            'sha256' => hash('sha256', json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        ];
    }

    $report = json_decode((string) file_get_contents($root.'/docs/report-schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $push = json_decode((string) file_get_contents($root.'/docs/push-schema.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($report['x-excubia-version'])->toBe($report['properties']['schemaVersion']['const'])
        ->and($report['x-excubia-version'])->toBe(HealthReportData::SCHEMA_VERSION)
        ->and($push['x-excubia-version'])->toBe($push['properties']['protocolVersion']['const'])
        ->and($push['x-excubia-version'])->toBe(ReportPushService::PROTOCOL_VERSION)
        ->and($schemas)->toBe(contractSnapshot('schemas'), CONTRACT_CHANGE_MESSAGE);
});

it('freezes the check ids, their order and the SARIF rule ids', function (): void {
    $sarif = contractEmptyProjectSarif();
    $ruleIds = array_column($sarif['runs'][0]['tool']['driver']['rules'], 'id');

    expect($ruleIds)->toBe(contractSnapshot('checkIds'), CONTRACT_CHANGE_MESSAGE);
});

it('freezes the SARIF fingerprint key and the report fingerprint recipe', function (): void {
    $finding = static fn (?string $advisoryId): FindingData => new FindingData(
        checkId: 'composer-advisories',
        severity: SeverityEnum::High,
        status: FindingStatusEnum::Fail,
        subject: 'laravel/framework',
        message: 'Wording is internal.',
        fixHint: '',
        evidenceUrl: null,
        advisoryId: $advisoryId,
    );
    $sarif = (new SarifRenderer)->toArray(new HealthReportData([
        CheckResultData::fromFindings('composer-advisories', 'Known vulnerabilities (Composer)', [$finding('PKSA-2y3q-xh2b-7vqk')]),
    ], []));

    expect([
        'fingerprintKeys' => array_keys($sarif['runs'][0]['results'][0]['partialFingerprints']),
        'sarifVersion' => $sarif['version'],
        'fingerprintWithAdvisory' => $finding('PKSA-2y3q-xh2b-7vqk')->fingerprint(),
        'fingerprintWithoutAdvisory' => $finding(null)->fingerprint(),
    ])->toBe(contractSnapshot('sarif'), CONTRACT_CHANGE_MESSAGE)
        ->and($finding('PKSA-2y3q-xh2b-7vqk')->fingerprint())->toBe(hash('sha256', "composer-advisories\0laravel/framework\0PKSA-2y3q-xh2b-7vqk"))
        ->and($finding(null)->fingerprint())->toBe(hash('sha256', "composer-advisories\0laravel/framework\0"));
});

it('freezes the exit codes and the skipReason values', function (): void {
    $exitCodes = [];

    foreach (ExitCodeEnum::cases() as $case) {
        $exitCodes[$case->name] = $case->value;
    }

    expect($exitCodes)->toBe(contractSnapshot('exitCodes'), CONTRACT_CHANGE_MESSAGE)
        ->and(array_map(static fn (SkipReasonEnum $reason): string => $reason->value, SkipReasonEnum::cases()))
        ->toBe(contractSnapshot('skipReasons'), CONTRACT_CHANGE_MESSAGE);
});

it('freezes the bin/excubia commands and their options', function (): void {
    $commands = [];

    foreach ([new CheckCommand, new PushCommand, new ClearCacheCommand] as $command) {
        $commands[(string) $command->getName()] = contractCommandDefinition($command);
    }

    expect($commands)->toBe(contractSnapshot('cli'), CONTRACT_CHANGE_MESSAGE);
});

it('freezes the Artisan commands, their options and the heartbeat cache key', function (): void {
    $commands = [];

    foreach ([new ExcubiaCheckCommand, new ExcubiaPushCommand, new ExcubiaPushRuntimeCommand, new ExcubiaHeartbeatCommand] as $command) {
        $commands[(string) $command->getName()] = contractCommandDefinition($command);
    }

    expect([
        'commands' => $commands,
        'heartbeatCacheKey' => ExcubiaHeartbeatCommand::CACHE_KEY,
    ])->toBe(contractSnapshot('artisan'), CONTRACT_CHANGE_MESSAGE);
});

it('freezes the environment variables read in src', function (): void {
    $variables = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            preg_match_all("/['\"]((?:EXCUBIA_[A-Z0-9_]+)|GITHUB_TOKEN)['\"]/", (string) file_get_contents($file->getPathname()), $matches);
            $variables = [...$variables, ...$matches[1]];
        }
    }

    $variables = array_values(array_unique($variables));
    sort($variables);

    expect($variables)->toBe(contractSnapshot('environmentVariables'), CONTRACT_CHANGE_MESSAGE);
});

it('freezes the push protocol version, types and endpoints', function (): void {
    expect([
        'protocolVersion' => ReportPushService::PROTOCOL_VERSION,
        'endpoints' => [
            'report' => ReportPushService::REPORTS_PATH,
            'runtime' => ReportPushService::RUNTIME_PATH,
            'exceptions' => ReportPushService::EXCEPTIONS_PATH,
        ],
        'types' => [ReportPushService::RUNTIME_TYPE, ReportPushService::EXCEPTIONS_TYPE],
        'runtimeBucketSeconds' => ReportPushService::RUNTIME_BUCKET_SECONDS,
        'runtimeHistogramBoundsMs' => RuntimeTypeEnum::HISTOGRAM_BOUNDS_MS,
    ])->toBe(contractSnapshot('push'), CONTRACT_CHANGE_MESSAGE);
});

it('documents the runtime histogram bounds in the runtime schema, from the one constant', function (): void {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/docs/runtime-schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $histogram = $schema['$defs']['histogram'];
    $durationTypes = array_keys(array_filter($schema['$defs'], static fn (array $definition): bool => isset($definition['properties']['maxMs'])));

    expect($histogram['x-excubia-bucket-bounds-ms'])->toBe(RuntimeTypeEnum::HISTOGRAM_BOUNDS_MS)
        ->and($histogram['minItems'])->toBe(count(RuntimeTypeEnum::HISTOGRAM_BOUNDS_MS) + 1)
        ->and($histogram['maxItems'])->toBe(count(RuntimeTypeEnum::HISTOGRAM_BOUNDS_MS) + 1)
        ->and($durationTypes)->toEqualCanonicalizing(['request', 'slowQuery', 'job', 'scheduledTask', 'outgoingHttp', 'queueWait']);

    foreach ($durationTypes as $type) {
        expect($schema['$defs'][$type]['properties']['histogram'])->toBe(['$ref' => '#/$defs/histogram'])
            ->and($schema['$defs'][$type]['required'])->not->toContain('histogram');
    }
});

it('freezes the health endpoint header, path default and response shape', function (): void {
    $renderer = new OhDearHealthRenderer(new DateTimeImmutable('2026-11-03', new DateTimeZone('UTC')));
    $statuses = [];

    foreach (FindingStatusEnum::cases() as $status) {
        $payload = json_decode($renderer->render(new HealthReportData([new CheckResultData('php-eol', 'PHP end of life', $status, [])], [])), true, 512, JSON_THROW_ON_ERROR);
        $statuses[$status->value] = $payload['checkResults'][0]['status'];
    }

    $crashed = json_decode($renderer->renderCrashed(), true, 512, JSON_THROW_ON_ERROR);
    $notRunYet = json_decode($renderer->renderNotRunYet(), true, 512, JSON_THROW_ON_ERROR);

    expect([
        'secretHeader' => HealthEndpointService::SECRET_HEADER,
        'secretVariable' => HealthEndpointService::SECRET_VARIABLE,
        'minimumSecretLength' => HealthEndpointService::MIN_SECRET_LENGTH,
        'defaultPath' => ExcubiaConfigurationData::DEFAULT_HEALTH_ENDPOINT_PATH,
        'responseKeys' => array_keys($crashed),
        'checkResultKeys' => array_keys($crashed['checkResults'][0]),
        'statuses' => $statuses,
        'crashed' => [$crashed['checkResults'][0]['name'], $crashed['checkResults'][0]['status']],
        'notRunYet' => [$notRunYet['checkResults'][0]['name'], $notRunYet['checkResults'][0]['status']],
    ])->toBe(contractSnapshot('healthEndpoint'), CONTRACT_CHANGE_MESSAGE);
});

it('freezes the GitHub Action inputs, their defaults and the outputs', function (): void {
    expect([
        'inputs' => contractActionBlock('inputs'),
        'outputs' => array_keys(contractActionBlock('outputs')),
    ])->toBe(contractSnapshot('action'), CONTRACT_CHANGE_MESSAGE);
});
