<?php

declare(strict_types=1);

use Qiick\Excubia\Checks\PhpPlatformRequirementsCheck;
use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LaravelPostureData;
use Qiick\Excubia\Data\PlatformRequirementData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Services\ComposerConstraintService;
use Qiick\Excubia\Services\ProjectSnapshotService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A locked project with root platform requirements, a package that needs
 * intl and PHP 8.3, a polyfill that provides mbstring and a dev package that
 * needs xdebug. `config.platform` pretends intl exists.
 */
function platformRequirementsProject(): string
{
    return temporaryProject([
        'composer.json' => '{"require": {"php": "^8.2"}}',
        'composer.lock' => json_encode([
            'packages' => [
                ['name' => 'acme/needs-intl', 'version' => '1.0.0', 'require' => ['php' => '>=8.3', 'ext-intl' => '*', 'ext-MBString' => '*', 'acme/other' => '^1.0', 'lib-icu' => '>=70']],
                ['name' => 'symfony/polyfill-mbstring', 'version' => 'v1.31.0', 'require' => ['php' => '>=7.2'], 'provide' => ['ext-mbstring' => '*']],
                ['name' => 'acme/broken', 'version' => '1.0.0', 'require' => 'not-a-map'],
            ],
            'packages-dev' => [
                ['name' => 'acme/debugger', 'version' => '1.0.0', 'require' => ['ext-xdebug' => '*']],
            ],
            'platform' => ['php' => '^8.2', 'ext-json' => '*', 'ext-zend-opcache' => '*'],
            'platform-dev' => ['ext-pcov' => '*'],
            'platform-overrides' => ['ext-intl' => '1.0.0'],
        ], JSON_THROW_ON_ERROR),
    ]);
}

/**
 * @param  list<string>  $extensions
 */
function platformResult(string $phpVersion, array $extensions, bool $isArtisan = false, bool $includeDev = true): CheckResultData
{
    $defaults = ExcubiaConfigurationData::defaults();
    $configuration = new ExcubiaConfigurationData(
        failOnSeverity: $defaults->failOnSeverity,
        isDevelopmentDependencyIncluded: $includeDev,
        staleAfterMonths: 24,
        phpWarnDaysBeforeEndOfLife: 180,
        laravelWarnDaysBeforeEndOfLife: 180,
        isNodeEndOfLifeCheckEnabled: true,
        ignoreRules: [],
        cacheDirectory: null,
        cacheTtlHours: 24,
    );
    $snapshot = (new ProjectSnapshotService)->snapshot(platformRequirementsProject(), $configuration);

    if ($isArtisan) {
        $snapshot = $snapshot->withLaravelPosture(new LaravelPostureData('production', false, true, true, true, false, [], 0, null, true, null, new DateTimeImmutable));
    }

    return (new PhpPlatformRequirementsCheck(new ComposerConstraintService, $phpVersion, $extensions))->run($snapshot, $configuration);
}

it('passes when the running PHP meets every requirement', function () {
    $result = platformResult('8.4.1', ['Core', 'json', 'intl', 'Zend OPcache', 'xdebug', 'pcov']);

    expect($result->status->value)->toBe('pass')
        ->and($result->findings)->toBe([]);
});

it('fails at high for each missing extension and an unmet PHP version, and says the CLI checked its own PHP', function () {
    $result = platformResult('8.2.10', ['Core', 'json']);
    $bySubject = array_column(array_map(fn ($finding) => ['subject' => $finding->subject, 'finding' => $finding], $result->findings), 'finding', 'subject');

    expect($result->status->value)->toBe('fail')
        ->and(array_keys($bySubject))->toBe(['ext-zend-opcache', 'ext-pcov', 'php', 'ext-intl', 'ext-xdebug'])
        ->and($bySubject['php']->severity->value)->toBe('high')
        ->and($bySubject['php']->message)->toStartWith('PHP 8.2.10 does not satisfy the PHP requirement of acme/needs-intl (>=8.3).')
        ->and($bySubject['ext-intl']->message)->toStartWith('The intl extension is not loaded; required by acme/needs-intl.')
        ->and($bySubject['ext-intl']->message)->toContain('Checked against the PHP running vendor/bin/excubia (8.2.10)')
        ->and($bySubject['ext-intl']->fixHint)->toContain('composer.json config.platform declares ext-intl')
        ->and($bySubject['ext-pcov']->details)->toBe([
            'requirement' => 'ext-pcov',
            'requiredBy' => ['composer.json'],
            'checkedPhpVersion' => '8.2.10',
            'isStandaloneCli' => true,
        ]);
});

it('drops dev requirements under --no-dev and does not flag the CLI under Artisan', function () {
    $result = platformResult('8.4.1', ['json', 'intl', 'Zend OPcache'], isArtisan: true, includeDev: false);

    expect($result->status->value)->toBe('pass');

    $artisan = platformResult('8.4.1', ['json', 'Zend OPcache'], isArtisan: true);

    expect(array_map(fn ($finding) => $finding->subject, $artisan->findings))->toBe(['ext-pcov', 'ext-intl', 'ext-xdebug'])
        ->and($artisan->findings[1]->message)->not->toContain('vendor/bin/excubia')
        ->and($artisan->findings[1]->details['isStandaloneCli'])->toBeFalse();
});

it('reads the running PHP by default and passes on this repository', function () {
    $snapshot = new ProjectSnapshotData(null, null, [], [], null, platformRequirements: [
        new PlatformRequirementData('php', '>=8.2', PlatformRequirementData::ROOT_PACKAGE, false),
        new PlatformRequirementData('ext-json', '*', PlatformRequirementData::ROOT_PACKAGE, false),
    ]);

    expect((new PhpPlatformRequirementsCheck)->run($snapshot, ExcubiaConfigurationData::defaults())->status->value)->toBe('pass');
});

it('runs in the standalone CLI and reports through the JSON report', function () {
    $tester = new CommandTester(new CheckCommand(new FakeHttpClient([])));
    $tester->execute(['path' => platformRequirementsProject(), '--format' => 'json', '--no-banner' => true, '--only' => 'php-platform-requirements', '--fail-on' => 'none', '--no-dev' => true]);
    $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    $subjects = array_column($report['checks'][0]['findings'], 'subject');

    expect($report['checks'][0]['checkId'])->toBe('php-platform-requirements')
        ->and($subjects)->not->toContain('ext-xdebug', 'ext-pcov', 'ext-mbstring', 'lib-icu');
});
