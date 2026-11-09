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
use Qiick\Excubia\Laravel\PhpWebRuntimeSnapshotService;
use Qiick\Excubia\Laravel\QueueWorkerHeartbeatService;
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

it('freezes the Artisan commands, their options and the heartbeat cache keys', function (): void {
    $commands = [];

    foreach ([new ExcubiaCheckCommand, new ExcubiaPushCommand, new ExcubiaPushRuntimeCommand, new ExcubiaHeartbeatCommand] as $command) {
        $commands[(string) $command->getName()] = contractCommandDefinition($command);
    }

    expect([
        'commands' => $commands,
        'heartbeatCacheKey' => ExcubiaHeartbeatCommand::CACHE_KEY,
        'workerHeartbeatCacheKeyPrefix' => QueueWorkerHeartbeatService::CACHE_KEY_PREFIX,
        'phpWebRuntimeCacheKey' => PhpWebRuntimeSnapshotService::CACHE_KEY,
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
        ->and($durationTypes)->toEqualCanonicalizing(['request', 'slowQuery', 'job', 'scheduledTask', 'outgoingHttp', 'queueWait', 'command', 'ai', 'aiTool', 'billingWebhook', 'scout']);

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

/*
 * docs/CONTRACTS.md against the snapshot (#187): every list that the
 * document and tests/Fixtures/Contracts/public-contracts.json both hold must
 * match, so the human-readable page cannot drift from the frozen contracts.
 */

const CONTRACT_DOCUMENT_MESSAGE = 'docs/CONTRACTS.md differs from tests/Fixtures/Contracts/public-contracts.json: '
    .'update the list in docs/CONTRACTS.md to match the snapshot.';

/**
 * The body of one `### <heading>` section of docs/CONTRACTS.md, up to the
 * next heading of level 2 or 3.
 */
function contractDocumentSection(string $heading): string
{
    $document = (string) file_get_contents(dirname(__DIR__, 2).'/docs/CONTRACTS.md');
    $isFound = preg_match('/^### '.preg_quote($heading, '/').'\n(.*?)(?=^#{2,3} |\z)/ms', $document, $match);

    expect($isFound)->toBe(1, 'docs/CONTRACTS.md has no "### '.$heading.'" section.');

    return $match[1];
}

/**
 * Every code span of a Markdown text, in order.
 *
 * @return list<string>
 */
function contractDocumentCodeSpans(string $text): array
{
    preg_match_all('/`([^`]+)`/', $text, $matches);

    return $matches[1];
}

it('lists every check id in docs/CONTRACTS.md, in run order, as in the snapshot', function (): void {
    preg_match_all('/^\| (\d+) \| `([a-z0-9-]+)` \|$/m', contractDocumentSection('Check ids'), $rows);

    expect($rows[2])->toBe(contractSnapshot('checkIds'), CONTRACT_DOCUMENT_MESSAGE)
        ->and(array_map(intval(...), $rows[1]))->toBe(range(1, count($rows[1])), 'The check id table in docs/CONTRACTS.md is not numbered 1, 2, 3, … in order.');
});

it('lists the exit codes in docs/CONTRACTS.md as in the snapshot', function (): void {
    preg_match_all('/^\| `(\d+)` \| `([A-Za-z]+)` \|/m', contractDocumentSection('Exit codes'), $rows);

    expect(array_combine($rows[2], array_map(intval(...), $rows[1])))->toBe(contractSnapshot('exitCodes'), CONTRACT_DOCUMENT_MESSAGE);
});

it('lists the commands, their arguments and options in docs/CONTRACTS.md as in the snapshot', function (): void {
    preg_match_all('/^- `(?:bin\/excubia|php artisan) ([a-z:-]+)( \[path\])?`: (.*)$/m', contractDocumentSection('Commands and options'), $lines, PREG_SET_ORDER);
    $documented = [];

    foreach ($lines as [, $name, $path, $description]) {
        $options = array_values(array_filter(contractDocumentCodeSpans($description), static fn (string $span): bool => str_starts_with($span, '--')));

        if (str_contains($description, 'the `check` options')) {
            $options = $documented['check']['options'];
        }

        $documented[$name] = ['arguments' => $path === '' ? [] : ['path'], 'options' => $options];
    }

    $snapshot = [];

    foreach ([...contractSnapshot('cli'), ...contractSnapshot('artisan')['commands']] as $name => $definition) {
        $snapshot[$name] = ['arguments' => array_keys($definition['arguments']), 'options' => array_keys($definition['options'])];
    }

    expect($documented)->toBe($snapshot, CONTRACT_DOCUMENT_MESSAGE);
});

it('names the heartbeat and runtime cache keys in docs/CONTRACTS.md as in the snapshot', function (): void {
    $artisan = contractSnapshot('artisan');
    $cacheKeys = array_values(array_filter(
        contractDocumentCodeSpans(contractDocumentSection('Scheduled entries and heartbeat keys')),
        static fn (string $span): bool => preg_match('/^excubia:(scheduler-heartbeat|[a-z-]+:[a-z]*)$/', $span) === 1,
    ));

    expect($cacheKeys)->toBe([$artisan['heartbeatCacheKey'], $artisan['workerHeartbeatCacheKeyPrefix'], $artisan['phpWebRuntimeCacheKey']], CONTRACT_DOCUMENT_MESSAGE);
});

it('lists the environment variables in docs/CONTRACTS.md as in the snapshot', function (): void {
    preg_match('/^- Environment variables: (.*)$/m', contractDocumentSection('Configuration and environment'), $line);
    $variables = array_values(array_unique(array_filter(
        contractDocumentCodeSpans($line[1] ?? ''),
        static fn (string $span): bool => preg_match('/^(EXCUBIA_[A-Z0-9_]+|GITHUB_TOKEN)$/', $span) === 1,
    )));
    sort($variables);

    expect($variables)->toBe(contractSnapshot('environmentVariables'), CONTRACT_DOCUMENT_MESSAGE);
});

it('lists the push endpoints, types, bucket and histogram bounds in docs/CONTRACTS.md as in the snapshot', function (): void {
    $section = contractDocumentSection('Push protocol');
    preg_match_all('/^\| `POST \{url\}(\/[a-z\/]+)` \| (?:—|`([a-z]+)`) \|/m', $section, $rows);
    preg_match('/bucket upper bounds are part of the contract: (.*?) ms, inclusive/', $section, $bounds);
    $push = contractSnapshot('push');

    expect($rows[1])->toBe(array_values($push['endpoints']), CONTRACT_DOCUMENT_MESSAGE)
        ->and(array_values(array_filter($rows[2])))->toBe($push['types'], CONTRACT_DOCUMENT_MESSAGE)
        ->and($section)->toContain('`bucketSeconds` `'.$push['runtimeBucketSeconds'].'`')
        ->and(array_map(intval(...), contractDocumentCodeSpans($bounds[1] ?? '')))->toBe($push['runtimeHistogramBoundsMs'], CONTRACT_DOCUMENT_MESSAGE);
});

it('lists the health endpoint statuses in docs/CONTRACTS.md as in the snapshot', function (): void {
    preg_match('/statuses (.*?);/', contractDocumentSection('Health endpoint'), $statuses);
    $health = contractSnapshot('healthEndpoint');

    expect(contractDocumentCodeSpans($statuses[1] ?? ''))->toBe([...array_values($health['statuses']), $health['crashed'][1]], CONTRACT_DOCUMENT_MESSAGE);
});

it('lists the GitHub Action inputs, defaults and outputs in docs/CONTRACTS.md as in the snapshot', function (): void {
    preg_match('/^Inputs \(default\): (.*?)\. Outputs: (.*)$/m', contractDocumentSection('GitHub Action'), $line);
    preg_match_all('/`([a-z-]+)` \((?:`([^`]*)`|empty)\)/', $line[1] ?? '', $inputs);
    preg_match_all('/(?:^|, )`([a-z-]+)`/', $line[2] ?? '', $outputs);

    expect(['inputs' => array_combine($inputs[1], $inputs[2]), 'outputs' => $outputs[1]])
        ->toBe(contractSnapshot('action'), CONTRACT_DOCUMENT_MESSAGE);
});

it('lists the skipReason values in docs/CONTRACTS.md as in the snapshot', function (): void {
    $firstSentence = explode('. ', trim(contractDocumentSection('`skipReason`')))[0];

    expect(contractDocumentCodeSpans($firstSentence))->toBe(contractSnapshot('skipReasons'), CONTRACT_DOCUMENT_MESSAGE);
});
