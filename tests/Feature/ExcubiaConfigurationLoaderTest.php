<?php

declare(strict_types=1);

use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

it('uses defaults when excubia.json is absent', function () {
    $root = temporaryProject([]);

    expect((new ExcubiaConfigurationLoader)->load(null, $root)->staleAfterMonths)
        ->toBe(ExcubiaConfigurationData::defaults()->staleAfterMonths);
});

it('loads the spec example and matches an advisory id', function () {
    $root = temporaryProject([
        'excubia.json' => <<<'JSON'
{
  "$schema": "https://raw.githubusercontent.com/qiick-io/excubia-core/main/excubia.schema.json",
  "failOn": "high",
  "includeDev": true,
  "checks": {
    "composer-stale": { "staleAfterMonths": 12 },
    "php-eol": { "warnDaysBeforeEol": 90 },
    "laravel-eol": { "warnDaysBeforeEol": 30 },
    "node-eol": { "enabled": false }
  },
  "ignore": [
    { "checkId": "composer-advisories", "subject": "vendor/package", "advisoryId": "GHSA-xxxx", "reason": "Not reachable", "until": "2027-01-31" }
  ],
  "cache": { "directory": null, "ttlHours": 12 }
}
JSON,
    ]);

    $configuration = (new ExcubiaConfigurationLoader)->load(null, $root);
    $today = new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC'));
    $matched = advisoryFinding('GHSA-xxxx');
    $other = advisoryFinding('GHSA-other');

    expect($configuration->staleAfterMonths)->toBe(12)
        ->and($configuration->phpWarnDaysBeforeEndOfLife)->toBe(90)
        ->and($configuration->laravelWarnDaysBeforeEndOfLife)->toBe(30)
        ->and($configuration->isNodeEndOfLifeCheckEnabled)->toBeFalse()
        ->and($configuration->cacheTtlHours)->toBe(12)
        ->and($configuration->isIgnored($matched, $today))->toBeTrue()
        ->and($configuration->isIgnored($other, $today))->toBeFalse();
});

it('rejects an unknown key', function () {
    $root = temporaryProject([
        'excubia.json' => '{"telemetry": true}',
    ]);

    expect(fn () => (new ExcubiaConfigurationLoader)->load(null, $root))
        ->toThrow(InvalidConfigurationException::class, 'Unknown excubia.json key [telemetry].');
});

it('loads the posture thresholds and defaults them to 10 minutes and 24 hours', function () {
    $root = temporaryProject([
        'excubia.json' => '{"checks": {"laravel-scheduler-heartbeat": {"staleAfterMinutes": 30}, "laravel-failed-jobs": {"highSeverityAfterHours": 6}}}',
    ]);

    $configuration = (new ExcubiaConfigurationLoader)->load(null, $root);

    expect($configuration->schedulerHeartbeatStaleAfterMinutes)->toBe(30)
        ->and($configuration->failedJobHighSeverityAfterHours)->toBe(6)
        ->and(ExcubiaConfigurationData::defaults()->schedulerHeartbeatStaleAfterMinutes)->toBe(10)
        ->and(ExcubiaConfigurationData::defaults()->failedJobHighSeverityAfterHours)->toBe(24);
});

it('loads the late-run grace for excubia-app and defaults it to 5 minutes', function () {
    $root = temporaryProject([
        'excubia.json' => '{"checks": {"laravel-scheduler-heartbeat": {"staleAfterMinutes": 30, "lateAfterMinutes": 15}}}',
    ]);

    $configuration = (new ExcubiaConfigurationLoader)->load(null, $root);

    expect($configuration->schedulerLateAfterMinutes)->toBe(15)
        ->and($configuration->schedulerHeartbeatStaleAfterMinutes)->toBe(30)
        ->and(ExcubiaConfigurationData::defaults()->schedulerLateAfterMinutes)->toBe(5);
});

it('rejects posture thresholds below one and unknown posture keys', function (string $document, string $message) {
    $root = temporaryProject(['excubia.json' => $document]);

    expect(fn () => (new ExcubiaConfigurationLoader)->load(null, $root))
        ->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'heartbeat zero' => ['{"checks": {"laravel-scheduler-heartbeat": {"staleAfterMinutes": 0}}}', 'staleAfterMinutes must be at least 1.'],
    'late grace zero' => ['{"checks": {"laravel-scheduler-heartbeat": {"lateAfterMinutes": 0}}}', 'lateAfterMinutes must be at least 1.'],
    'late grace not an integer' => ['{"checks": {"laravel-scheduler-heartbeat": {"lateAfterMinutes": "5"}}}', 'checks.laravel-scheduler-heartbeat.lateAfterMinutes'],
    'unknown heartbeat key' => ['{"checks": {"laravel-scheduler-heartbeat": {"missedAfterMinutes": 3}}}', 'Unknown checks.laravel-scheduler-heartbeat key [missedAfterMinutes].'],
    'failed jobs zero' => ['{"checks": {"laravel-failed-jobs": {"highSeverityAfterHours": 0}}}', 'highSeverityAfterHours must be at least 1.'],
    'unknown key' => ['{"checks": {"laravel-failed-jobs": {"maxCount": 3}}}', 'Unknown checks.laravel-failed-jobs key [maxCount].'],
]);

it('rejects an ignore rule without a reason', function () {
    $root = temporaryProject([
        'excubia.json' => '{"ignore":[{"checkId":"php-eol","subject":"php"}]}',
    ]);

    expect(fn () => (new ExcubiaConfigurationLoader)->load(null, $root))
        ->toThrow(InvalidConfigurationException::class, 'reason');
});

it('reactivates an expired ignore and returns a warning', function () {
    $root = temporaryProject([
        'excubia.json' => '{"ignore":[{"checkId":"composer-advisories","subject":"vendor/package","advisoryId":"GHSA-xxxx","reason":"Not reachable","until":"2026-01-01"}]}',
    ]);
    $configuration = (new ExcubiaConfigurationLoader)->load(null, $root);
    $finding = advisoryFinding('GHSA-xxxx');
    $today = new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC'));

    expect($configuration->isIgnored($finding, $today))->toBeFalse()
        ->and($configuration->expiredIgnoreWarnings([$finding], $today)[0])->toContain('expired on 2026-01-01');
});

it('rejects a missing explicit config path', function () {
    expect(fn () => (new ExcubiaConfigurationLoader)->load('/tmp/does-not-exist-excubia.json', '/tmp'))
        ->toThrow(InvalidConfigurationException::class, 'was not found');
});

it('documents a closed schema with advisoryId on ignore rules', function () {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/excubia.schema.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['ignore']['items']['properties'])->toHaveKey('advisoryId')
        ->and($schema['properties']['ignore']['items']['required'])->toBe(['checkId', 'subject', 'reason']);
});

it('reads healthEndpoint and keeps the endpoint off by default', function () {
    $loader = new ExcubiaConfigurationLoader;
    $defaults = $loader->load(null, temporaryProject([]));
    $configured = $loader->load(null, temporaryProject([
        'excubia.json' => '{"healthEndpoint": {"enabled": true, "path": "/ops/health/"}}',
    ]));

    expect($defaults->isHealthEndpointEnabled)->toBeFalse()
        ->and($defaults->healthEndpointPath)->toBe('excubia/health')
        ->and($configured->isHealthEndpointEnabled)->toBeTrue()
        ->and($configured->healthEndpointPath)->toBe('ops/health');
});

it('rejects an invalid healthEndpoint', function (string $healthEndpoint, string $message) {
    expect(fn () => (new ExcubiaConfigurationLoader)->load(null, temporaryProject([
        'excubia.json' => '{"healthEndpoint": '.$healthEndpoint.'}',
    ])))->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'not an object' => ['true', 'healthEndpoint must be an object.'],
    'secret in the file' => ['{"secret": "x"}', 'Unknown healthEndpoint key [secret].'],
    'enabled not boolean' => ['{"enabled": "yes"}', 'healthEndpoint.enabled must be a boolean.'],
    'path with a query' => ['{"path": "health?x=1"}', 'healthEndpoint.path must be a relative URL path'],
    'empty path' => ['{"path": "/"}', 'healthEndpoint.path must be a relative URL path'],
]);

it('documents healthEndpoint in the schema', function () {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/excubia.schema.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($schema['properties']['healthEndpoint']['additionalProperties'])->toBeFalse()
        ->and(array_keys($schema['properties']['healthEndpoint']['properties']))->toBe(['enabled', 'path']);
});

function advisoryFinding(string $advisoryId): FindingData
{
    return new FindingData(
        checkId: 'composer-advisories',
        severity: SeverityEnum::Critical,
        status: FindingStatusEnum::Fail,
        subject: 'vendor/package',
        message: 'Known vulnerability.',
        fixHint: 'Update vendor/package to ≥ fixed version ('.$advisoryId.').',
        evidenceUrl: null,
        advisoryId: $advisoryId,
    );
}

it('loads the excubia-app url and token and rejects unknown app keys', function () {
    $root = temporaryProject([
        'excubia.json' => '{"app": {"url": "https://excubia.example.test", "token": "project-token"}}',
    ]);
    $configuration = (new ExcubiaConfigurationLoader)->load(null, $root);

    expect($configuration->appUrl)->toBe('https://excubia.example.test')
        ->and($configuration->appToken)->toBe('project-token')
        ->and(ExcubiaConfigurationData::defaults()->appUrl)->toBeNull();

    $invalid = temporaryProject(['excubia.json' => '{"app": {"url": "https://excubia.example.test", "secret": "x"}}']);

    expect(fn () => (new ExcubiaConfigurationLoader)->load(null, $invalid))
        ->toThrow(InvalidConfigurationException::class, 'Unknown app key [secret]');
});

it('loads the infrastructure check settings and defaults them to 80 / 90 %, 15 minutes and 1000 jobs', function () {
    $root = temporaryProject([
        'excubia.json' => '{"checks": {"laravel-database": {"connections": ["replica"]}, "laravel-disk-space": {"warnAtPercentUsed": 70, "failAtPercentUsed": 95}, "laravel-queue-backlog": {"maxOldestAgeMinutes": 5, "maxPendingJobs": 50, "queues": ["emails"]}}}',
    ]);

    $configuration = (new ExcubiaConfigurationLoader)->load(null, $root);
    $defaults = ExcubiaConfigurationData::defaults();
    $partial = (new ExcubiaConfigurationLoader)->load(null, temporaryProject(['excubia.json' => '{"checks": {"laravel-disk-space": {"failAtPercentUsed": 99}, "laravel-queue-backlog": {}}}']));

    expect($configuration->databaseConnectionNames)->toBe(['replica'])
        ->and($configuration->diskSpaceWarnAtPercentUsed)->toBe(70)
        ->and($configuration->diskSpaceFailAtPercentUsed)->toBe(95)
        ->and($configuration->queueBacklogMaxOldestAgeMinutes)->toBe(5)
        ->and($configuration->queueBacklogMaxPendingJobs)->toBe(50)
        ->and($configuration->queueBacklogQueueNames)->toBe(['emails'])
        ->and([$defaults->databaseConnectionNames, $defaults->diskSpaceWarnAtPercentUsed, $defaults->diskSpaceFailAtPercentUsed, $defaults->queueBacklogMaxOldestAgeMinutes, $defaults->queueBacklogMaxPendingJobs, $defaults->queueBacklogQueueNames])
        ->toBe([[], 80, 90, 15, 1000, []])
        ->and([$partial->diskSpaceWarnAtPercentUsed, $partial->diskSpaceFailAtPercentUsed, $partial->queueBacklogMaxPendingJobs])->toBe([80, 99, 1000]);
});

it('rejects invalid infrastructure check settings', function (string $document, string $message) {
    $root = temporaryProject(['excubia.json' => $document]);

    expect(fn () => (new ExcubiaConfigurationLoader)->load(null, $root))
        ->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'warn above fail' => ['{"checks": {"laravel-disk-space": {"warnAtPercentUsed": 95, "failAtPercentUsed": 90}}}', 'laravel-disk-space needs 1 <= warnAtPercentUsed <= failAtPercentUsed <= 100.'],
    'fail above 100' => ['{"checks": {"laravel-disk-space": {"failAtPercentUsed": 101}}}', 'laravel-disk-space needs'],
    'warn zero' => ['{"checks": {"laravel-disk-space": {"warnAtPercentUsed": 0}}}', 'laravel-disk-space needs'],
    'percent not an integer' => ['{"checks": {"laravel-disk-space": {"warnAtPercentUsed": "80"}}}', 'checks.laravel-disk-space.warnAtPercentUsed must be an integer.'],
    'age zero' => ['{"checks": {"laravel-queue-backlog": {"maxOldestAgeMinutes": 0}}}', 'maxOldestAgeMinutes and maxPendingJobs must be at least 1.'],
    'pending zero' => ['{"checks": {"laravel-queue-backlog": {"maxPendingJobs": 0}}}', 'maxOldestAgeMinutes and maxPendingJobs must be at least 1.'],
    'queues not a list' => ['{"checks": {"laravel-queue-backlog": {"queues": "emails"}}}', 'checks.laravel-queue-backlog.queues must be a list of strings.'],
    'empty connection' => ['{"checks": {"laravel-database": {"connections": [""]}}}', 'checks.laravel-database.connections[0] must be a non-empty string.'],
    'unknown key' => ['{"checks": {"laravel-database": {"timeout": 3}}}', 'Unknown checks.laravel-database key [timeout].'],
    'not an object' => ['{"checks": {"laravel-disk-space": 80}}', 'checks.laravel-disk-space must be an object.'],
]);

it('rejects an empty probe name in a hand-built configuration', function () {
    $defaults = ExcubiaConfigurationData::defaults();

    expect(fn () => new ExcubiaConfigurationData(
        failOnSeverity: $defaults->failOnSeverity,
        isDevelopmentDependencyIncluded: true,
        staleAfterMonths: 24,
        phpWarnDaysBeforeEndOfLife: 180,
        laravelWarnDaysBeforeEndOfLife: 180,
        isNodeEndOfLifeCheckEnabled: true,
        ignoreRules: [],
        cacheDirectory: null,
        cacheTtlHours: 24,
        queueBacklogQueueNames: [''],
    ))->toThrow(InvalidConfigurationException::class, 'must be non-empty names.');
});
