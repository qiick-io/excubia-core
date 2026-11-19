<?php

declare(strict_types=1);

use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Exceptions\LockfileNotFoundException;
use Qiick\Excubia\Services\ProjectSnapshotService;

function projectFixture(string $name): string
{
    return dirname(__DIR__).'/Fixtures/Projects/'.$name;
}

it('reads the healthy laravel 13 fixture', function () {
    $snapshot = (new ProjectSnapshotService)->snapshot(projectFixture('healthy-laravel-13'), ExcubiaConfigurationData::defaults());

    expect($snapshot->hasNpmLockfile)->toBeFalse()
        ->and($snapshot->phpVersion)->toBe('8.4.1.0')
        ->and($snapshot->laravelFrameworkVersion)->toBe('v13.39.0')
        ->and($snapshot->nodeVersionDeclaration)->toBe('22')
        ->and($snapshot->composerPackages)->toHaveCount(2)
        ->and($snapshot->composerPackages[0]->isDirectDependency)->toBeTrue()
        ->and($snapshot->composerPackages[1]->name)->toBe('phpunit/phpunit')
        ->and($snapshot->composerPackages[1]->isDevelopmentDependency)->toBeTrue();
});

it('uses the require.php lower bound before the lock platform', function () {
    $snapshot = (new ProjectSnapshotService)->snapshot(projectFixture('outdated-laravel-10'), ExcubiaConfigurationData::defaults());

    expect($snapshot->phpVersion)->toBe('8.1.0.0')
        ->and($snapshot->laravelFrameworkVersion)->toBe('v10.48.22');
});

it('reports the running PHP for the app the core runs in (#273)', function () {
    $snapshot = (new ProjectSnapshotService(runningPhpVersion: '8.5.1'))->snapshot(projectFixture('outdated-laravel-10'), ExcubiaConfigurationData::defaults(), isRunningApplication: true);

    expect($snapshot->phpVersion)->toBe('8.5.1.0');
});

it('keeps the composer order for a project analysed from outside (#273)', function () {
    $service = new ProjectSnapshotService(runningPhpVersion: '8.5.1');

    expect($service->snapshot(projectFixture('outdated-laravel-10'), ExcubiaConfigurationData::defaults())->phpVersion)->toBe('8.1.0.0')
        ->and($service->snapshot(projectFixture('healthy-laravel-13'), ExcubiaConfigurationData::defaults())->phpVersion)->toBe('8.4.1.0');
});

it('reads the running PHP from the PHP constants by default (#273)', function () {
    $snapshot = (new ProjectSnapshotService)->snapshot(projectFixture('outdated-laravel-10'), ExcubiaConfigurationData::defaults(), isRunningApplication: true);

    expect($snapshot->phpVersion)->toBe(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION.'.0');
});

it('marks direct and transitive composer packages', function () {
    $snapshot = (new ProjectSnapshotService)->snapshot(projectFixture('abandoned-archived'), ExcubiaConfigurationData::defaults());
    $byName = [];

    foreach ($snapshot->composerPackages as $package) {
        $byName[$package->name] = $package;
    }

    expect($byName['jenssegers/agent']->isDirectDependency)->toBeTrue()
        ->and($byName['symfony/polyfill-php80']->isDirectDependency)->toBeFalse();
});

it('reads an npm lockfile without treating the project root as a package', function () {
    $snapshot = (new ProjectSnapshotService)->snapshot(projectFixture('npm-vulnerable'), ExcubiaConfigurationData::defaults());
    $byName = [];

    foreach ($snapshot->npmPackages as $package) {
        $byName[$package->name] = $package;
    }

    expect($snapshot->hasNpmLockfile)->toBeTrue()
        ->and($snapshot->nodeVersionDeclaration)->toBe('>=20')
        ->and($snapshot->laravelFrameworkVersion)->toBeNull()
        ->and($byName['lodash']->version)->toBe('4.17.20')
        ->and($byName['lodash']->isDirectDependency)->toBeTrue()
        ->and($byName['nested']->isDevelopmentDependency)->toBeTrue()
        ->and($byName['nested']->isDirectDependency)->toBeFalse();
});

it('leaves the laravel version empty for a non-laravel project', function () {
    $snapshot = (new ProjectSnapshotService)->snapshot(projectFixture('non-laravel'), ExcubiaConfigurationData::defaults());

    expect($snapshot->laravelFrameworkVersion)->toBeNull()
        ->and($snapshot->composerPackages[0]->name)->toBe('symfony/console');
});

it('rejects a broken lockfile with exit code 2', function () {
    $caught = null;

    try {
        (new ProjectSnapshotService)->snapshot(projectFixture('broken-lockfile'), ExcubiaConfigurationData::defaults());
    } catch (InvalidConfigurationException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(InvalidConfigurationException::class)
        ->and($caught?->getCode())->toBe(2)
        ->and($caught?->getMessage())->toContain('broken or unparseable');
});

it('requires composer.lock', function () {
    $root = temporaryProject([]);
    $caught = null;

    try {
        (new ProjectSnapshotService)->snapshot($root, ExcubiaConfigurationData::defaults());
    } catch (LockfileNotFoundException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(LockfileNotFoundException::class)
        ->and($caught?->getCode())->toBe(2);
});

it('omits composer dev packages when includeDev is false', function () {
    $configuration = new ExcubiaConfigurationData(
        failOnSeverity: null,
        isDevelopmentDependencyIncluded: false,
        staleAfterMonths: 24,
        phpWarnDaysBeforeEndOfLife: 180,
        laravelWarnDaysBeforeEndOfLife: 180,
        isNodeEndOfLifeCheckEnabled: true,
        ignoreRules: [],
        cacheDirectory: null,
        cacheTtlHours: 24,
    );
    $snapshot = (new ProjectSnapshotService)->snapshot(projectFixture('healthy-laravel-13'), $configuration);

    expect($snapshot->composerPackages)->toHaveCount(1)
        ->and($snapshot->composerPackages[0]->name)->toBe('laravel/framework');
});

it('falls through to the lock platform when composer.json has no php constraint', function () {
    $root = temporaryProject([
        'composer.lock' => json_encode([
            'packages' => [],
            'packages-dev' => [],
            'platform' => ['php' => '8.2.26'],
        ], JSON_THROW_ON_ERROR),
    ]);

    $snapshot = (new ProjectSnapshotService)->snapshot($root, ExcubiaConfigurationData::defaults());

    expect($snapshot->phpVersion)->toBe('8.2.26.0');
});
