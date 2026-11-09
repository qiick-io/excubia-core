<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\DependencyLicensesCheck;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\DependencyLicensesConfigurationData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\SpdxExpressionService;

/**
 * @param  list<LockedPackageData>  $composerPackages
 * @param  list<LockedPackageData>  $npmPackages
 */
function licensesResult(array $composerPackages, array $npmPackages = [], DependencyLicensesConfigurationData $policy = new DependencyLicensesConfigurationData, array $skippedPackagePatterns = []): CheckResultData
{
    $defaults = ExcubiaConfigurationData::defaults();
    $configuration = new ExcubiaConfigurationData(
        failOnSeverity: $defaults->failOnSeverity,
        isDevelopmentDependencyIncluded: true,
        staleAfterMonths: 24,
        phpWarnDaysBeforeEndOfLife: 180,
        laravelWarnDaysBeforeEndOfLife: 180,
        isNodeEndOfLifeCheckEnabled: true,
        ignoreRules: [],
        cacheDirectory: null,
        cacheTtlHours: 24,
        skippedPackagePatterns: $skippedPackagePatterns,
        dependencyLicenses: $policy,
    );

    return (new DependencyLicensesCheck(new SpdxExpressionService))
        ->run(new ProjectSnapshotData(null, null, $composerPackages, $npmPackages, null, $npmPackages !== []), $configuration);
}

function licensedPackage(string $name, array $licenses, bool $isDevelopmentDependency = false, string $version = '1.0.0', bool $isPrivate = false): LockedPackageData
{
    return new LockedPackageData($name, $version, true, $isDevelopmentDependency, $isPrivate, $licenses);
}

it('passes and sends nothing when every license is known or a permissive choice', function () {
    $result = licensesResult([
        licensedPackage('vendor/mit', ['MIT']),
        licensedPackage('vendor/choice', ['MIT', 'GPL-3.0-only']),
    ], [licensedPackage('dual', ['(MIT OR Apache-2.0)'])]);

    expect($result->status)->toBe(FindingStatusEnum::Pass)
        ->and($result->findings)->toBe([]);
});

it('warns at medium for strong copyleft, also inside AND', function () {
    $result = licensesResult([
        licensedPackage('vendor/gpl', ['GPL-3.0-only']),
        licensedPackage('vendor/both', ['MIT AND AGPL-3.0-or-later']),
    ]);

    expect($result->status)->toBe(FindingStatusEnum::Warn)
        ->and(array_map(fn ($finding) => [$finding->subject, $finding->status, $finding->severity], $result->findings))->toBe([
            ['vendor/gpl', FindingStatusEnum::Warn, SeverityEnum::Medium],
            ['vendor/both', FindingStatusEnum::Warn, SeverityEnum::Medium],
        ])
        ->and($result->findings[0]->message)->toBe('Strong copyleft license: GPL-3.0-only.')
        ->and($result->findings[0]->evidenceUrl)->toBe('https://packagist.org/packages/vendor/gpl')
        ->and($result->findings[0]->details)->toBe([
            'package' => 'vendor/gpl',
            'lockedVersion' => '1.0.0',
            'licenses' => ['GPL-3.0-only'],
            'ecosystem' => 'composer',
            'kind' => 'copyleft',
        ]);
});

it('warns at low for a missing or unknown license', function () {
    $result = licensesResult([licensedPackage('vendor/none', [])], [licensedPackage('custom', ['Acme-1.0'])]);

    expect($result->findings)->toHaveCount(2)
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::Low)
        ->and($result->findings[0]->message)->toBe('No license declared.')
        ->and($result->findings[0]->details['licenses'])->toBe([])
        ->and($result->findings[1]->severity)->toBe(SeverityEnum::Low)
        ->and($result->findings[1]->message)->toBe('Unknown license: Acme-1.0.')
        ->and($result->findings[1]->evidenceUrl)->toBe('https://www.npmjs.com/package/custom')
        ->and($result->findings[1]->details['ecosystem'])->toBe('npm')
        ->and(array_map(fn ($finding) => $finding->details['kind'], $result->findings))->toBe(['unknown', 'unknown']);
});

it('fails at high for a denied license and stays quiet for an allowed one', function () {
    $policy = new DependencyLicensesConfigurationData(allowedLicenses: ['GPL-*'], deniedLicenses: ['AGPL-3.0-only']);
    $result = licensesResult([
        licensedPackage('vendor/gpl', ['GPL-3.0-only']),
        licensedPackage('vendor/agpl', ['AGPL-3.0-only']),
    ], policy: $policy);

    expect($result->status)->toBe(FindingStatusEnum::Fail)
        ->and($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->subject)->toBe('vendor/agpl')
        ->and($result->findings[0]->status)->toBe(FindingStatusEnum::Fail)
        ->and($result->findings[0]->severity)->toBe(SeverityEnum::High)
        ->and($result->findings[0]->details['kind'])->toBe('denied');
});

it('leaves development dependencies out unless includeDev is on', function () {
    $packages = [licensedPackage('vendor/dev-gpl', ['GPL-3.0-only'], isDevelopmentDependency: true)];
    $npmPackages = [licensedPackage('dev-agpl', ['AGPL-3.0-only'], isDevelopmentDependency: true)];

    $without = licensesResult($packages, $npmPackages);
    $with = licensesResult($packages, $npmPackages, new DependencyLicensesConfigurationData(isDevelopmentDependencyIncluded: true));

    expect($without->findings)->toBe([])
        ->and($without->licenseInventory?->packageCount())->toBe(0)
        ->and(array_map(fn ($finding) => $finding->subject, $with->findings))->toBe(['vendor/dev-gpl', 'dev-agpl'])
        ->and($with->licenseInventory?->packageCount())->toBe(2);
});

it('reports a package locked in several npm paths once', function () {
    $result = licensesResult([], [
        licensedPackage('gpl-lib', ['GPL-3.0-only']),
        licensedPackage('gpl-lib', ['GPL-3.0-only']),
        licensedPackage('gpl-lib', ['GPL-3.0-only'], version: '2.0.0'),
    ]);

    expect($result->findings)->toHaveCount(2)
        ->and($result->licenseInventory?->ecosystems['npm'])->toBe(['packageCount' => 2, 'byLicense' => ['GPL-3.0-only' => 2]]);
});

it('omits the public registry link for private packages', function () {
    $result = licensesResult([
        licensedPackage('acme/in-house', ['proprietary'], isPrivate: true),
        licensedPackage('acme/published', ['proprietary']),
    ], skippedPackagePatterns: ['acme/pub*']);

    expect(array_map(fn ($finding) => $finding->evidenceUrl, $result->findings))->toBe([null, null]);
});

it('counts every inspected package per license id and ecosystem', function () {
    $result = licensesResult([
        licensedPackage('vendor/mit', ['MIT']),
        licensedPackage('vendor/choice', ['MIT', 'GPL-3.0-only']),
        licensedPackage('vendor/none', []),
    ], [
        licensedPackage('dual', ['(MIT OR Apache-2.0)']),
        licensedPackage('text', ['SEE LICENSE IN LICENSE']),
    ]);

    expect($result->licenseInventory?->ecosystems)->toBe([
        'composer' => ['packageCount' => 3, 'byLicense' => ['GPL-3.0-only' => 1, 'MIT' => 2, 'NOASSERTION' => 1]],
        'npm' => ['packageCount' => 2, 'byLicense' => ['Apache-2.0' => 1, 'MIT' => 1, 'SEE LICENSE IN LICENSE' => 1]],
    ])
        ->and($result->licenseInventory?->packageCount())->toBe(5)
        ->and($result->licenseInventory?->byLicense())->toBe([
            'Apache-2.0' => 1,
            'GPL-3.0-only' => 1,
            'MIT' => 3,
            'NOASSERTION' => 1,
            'SEE LICENSE IN LICENSE' => 1,
        ]);
});
