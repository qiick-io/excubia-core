<?php

declare(strict_types=1);

use Qiick\Excubia\Enums\SkipReasonEnum;

it('documents schema version 1 and the finding fields from the spec', function () {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/docs/report-schema.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($schema['properties']['schemaVersion']['const'])->toBe(1)
        ->and($schema['$defs']['finding']['required'])->toBe([
            'checkId',
            'severity',
            'status',
            'subject',
            'message',
            'fixHint',
            'evidenceUrl',
        ])
        ->and($schema['$defs']['finding']['properties']['advisoryId']['type'])->toBe(['string', 'null']);
});

it('documents the optional skipReason with the enum values', function () {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/docs/report-schema.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($schema['$defs']['finding']['required'])->not->toContain('skipReason')
        ->and($schema['$defs']['finding']['properties']['skipReason']['enum'])
        ->toBe(array_map(fn (SkipReasonEnum $reason): string => $reason->value, SkipReasonEnum::cases()));
});

it('documents push envelope version 1', function () {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/docs/push-schema.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($schema['properties']['protocolVersion']['const'])->toBe(1)
        ->and($schema['required'])->toBe(['protocolVersion', 'batchId', 'sentAt', 'report'])
        ->and($schema['properties']['report']['$ref'])->toEndWith('docs/report-schema.json');
});

it('documents the optional report fields for excubia-app and keeps them out of required', function () {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/docs/report-schema.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $finding = $schema['$defs']['finding'];

    expect($schema['required'])->toBe(['schemaVersion', 'checks', 'upgradePlan'])
        ->and(array_keys($schema['properties']))->toBe(['schemaVersion', 'generatedAt', 'excubiaVersion', 'release', 'deployedAt', 'project', 'checks', 'upgradePlan', 'licenses'])
        ->and($schema['$defs']['licenseInventory']['required'])->toBe(['packageCount', 'byLicense', 'byEcosystem'])
        ->and($schema['$defs']['licenseInventory']['properties']['byEcosystem']['required'])->toBe(['composer', 'npm'])
        ->and($schema['$defs']['project']['required'])->toBe(['php', 'laravel', 'node'])
        ->and(array_keys($schema['$defs']['project']['properties']))->not->toContain('name')
        ->and($schema['$defs']['projectRuntime']['required'])->toBe(['version', 'cycle', 'securitySupportEndsOn', 'activeSupportEndsOn'])
        ->and($schema['$defs']['project']['properties']['php']['anyOf'])->toBe([['type' => 'null'], ['$ref' => '#/$defs/projectRuntime']])
        ->and($finding['required'])->not->toContain('fingerprint')
        ->and($finding['required'])->not->toContain('details')
        ->and($finding['properties']['fingerprint']['pattern'])->toBe('^[0-9a-f]{64}$');
});

it('documents the details keys per check id', function () {
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/docs/report-schema.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $keys = static fn (string $definition): array => array_keys($schema['$defs'][$definition]['properties']);
    $ruledCheckIds = [];

    foreach ($schema['$defs']['finding']['allOf'] as $rule) {
        $checkId = $rule['if']['properties']['checkId'];
        $ruledCheckIds = [...$ruledCheckIds, ...($checkId['enum'] ?? [$checkId['const']])];
    }

    expect($keys('composerAdvisoryDetails'))->toBe(['package', 'lockedVersion', 'affectedVersions', 'fixedVersions'])
        ->and($keys('npmAdvisoryDetails'))->toBe(['package', 'lockedVersion', 'fixedVersions'])
        ->and($keys('endOfLifeDetails'))->toBe(['cycle', 'securitySupportEndsOn', 'activeSupportEndsOn'])
        ->and($keys('abandonedDetails'))->toBe(['package', 'lockedVersion', 'replacement'])
        ->and($keys('staleDetails'))->toBe(['package', 'lockedVersion'])
        ->and($keys('nextMajorBlockerDetails'))->toBe(['package', 'lockedVersion', 'targetMajor'])
        ->and($keys('privatePackagesDetails'))->toBe(['count'])
        ->and($keys('pendingMigrationsDetails'))->toBe(['count'])
        ->and($keys('failedJobsDetails'))->toBe(['count', 'oldestAgeHours'])
        ->and($keys('schedulerHeartbeatDetails'))->toBe(['minutesSinceLastRun'])
        ->and($keys('corsWildcardDetails'))->toBe(['hasCredentials'])
        ->and($keys('productionDriversDetails'))->toBe(['component', 'driver'])
        ->and($keys('devPackagesDetails'))->toBe(['package'])
        ->and($keys('databaseDetails'))->toBe(['connection'])
        ->and($keys('cacheDetails'))->toBe(['store'])
        ->and($keys('storageDetails'))->toBe(['directory'])
        ->and($keys('diskSpaceDetails'))->toBe(['usedPercent'])
        ->and($keys('queueBacklogDetails'))->toBe(['queue', 'pendingCount', 'oldestAgeMinutes'])
        ->and($keys('platformRequirementsDetails'))->toBe(['requirement', 'requiredBy', 'checkedPhpVersion', 'isStandaloneCli'])
        ->and($ruledCheckIds)->toBe([
            'composer-advisories',
            'npm-advisories',
            'php-eol',
            'laravel-eol',
            'node-eol',
            'composer-abandoned',
            'composer-stale',
            'laravel-next-blocker',
            'laravel-pending-migrations',
            'laravel-failed-jobs',
            'laravel-scheduler-heartbeat',
            'laravel-cors-wildcard',
            'laravel-production-drivers',
            'laravel-dev-packages',
            'dependency-licenses',
            'laravel-database',
            'laravel-cache',
            'laravel-storage',
            'laravel-disk-space',
            'laravel-queue-backlog',
            'php-platform-requirements',
        ])
        ->and($keys('dependencyLicensesDetails'))->toBe(['package', 'lockedVersion', 'licenses', 'ecosystem'])
        ->and($schema['$defs']['dependencyLicensesDetails']['required'])->toBe(['package', 'lockedVersion', 'licenses', 'ecosystem']);
});
