<?php

declare(strict_types=1);

use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\HealthReportData;
use Qiick\Excubia\Data\UpgradeStepData;
use Qiick\Excubia\Enums\ExitCodeEnum;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Enums\SkipReasonEnum;

function finding(
    FindingStatusEnum $status,
    SeverityEnum $severity,
    string $subject = 'php',
    ?string $advisoryId = null,
    string $checkId = 'php-eol',
): FindingData {
    return new FindingData(
        checkId: $checkId,
        severity: $severity,
        status: $status,
        subject: $subject,
        message: 'Example finding.',
        fixHint: 'Upgrade.',
        evidenceUrl: null,
        advisoryId: $advisoryId,
    );
}

function reportWith(FindingData ...$findings): HealthReportData
{
    return new HealthReportData(
        checkResults: [
            CheckResultData::fromFindings('php-eol', 'PHP end of life', array_values($findings)),
        ],
        upgradeSteps: [
            new UpgradeStepData(1, 'Upgrade PHP first.'),
        ],
    );
}

it('uses schema version 1', function () {
    expect((new HealthReportData([], []))->schemaVersion)->toBe(1);
});

it('ignores pass, skipped, info, and the upgrade plan when choosing the exit code', function () {
    $report = reportWith(
        finding(FindingStatusEnum::Pass, SeverityEnum::Critical),
        finding(FindingStatusEnum::Skipped, SeverityEnum::Critical, 'node'),
        finding(FindingStatusEnum::Fail, SeverityEnum::Info, 'laravel/framework'),
        finding(FindingStatusEnum::Warn, SeverityEnum::Medium, 'vendor/package'),
    );

    expect($report->exitCodeFor(SeverityEnum::High))->toBe(ExitCodeEnum::NoFindingsAtOrAboveFailOn)
        ->and($report->exitCodeFor(null))->toBe(ExitCodeEnum::NoFindingsAtOrAboveFailOn);
});

it('returns exit code 1 for a warn or fail at the threshold', function () {
    $warn = reportWith(finding(FindingStatusEnum::Warn, SeverityEnum::High));
    $fail = reportWith(finding(FindingStatusEnum::Fail, SeverityEnum::Critical));

    expect($warn->exitCodeFor(SeverityEnum::High))->toBe(ExitCodeEnum::FindingsAtOrAboveFailOn)
        ->and($fail->exitCodeFor(SeverityEnum::Critical))->toBe(ExitCodeEnum::FindingsAtOrAboveFailOn)
        ->and($warn->exitCodeFor(SeverityEnum::Critical))->toBe(ExitCodeEnum::NoFindingsAtOrAboveFailOn);
});

it('returns 3 only in strict mode for a data-source failure skip, after exit 1', function () {
    $failure = CheckResultData::skipped('php-eol', 'PHP end of life', 'php', 'endoflife.date did not respond.', SkipReasonEnum::DataSourceFailure);
    $deliberate = CheckResultData::skipped('node-eol', 'Node end of life', 'node', 'Node version is not declared.');
    $offline = CheckResultData::skipped('npm-advisories', 'Known vulnerabilities (npm)', 'npm', 'OSV.dev did not respond.', SkipReasonEnum::Offline);
    $critical = CheckResultData::fromFindings('laravel-eol', 'Laravel end of life', [finding(FindingStatusEnum::Fail, SeverityEnum::Critical)]);

    expect((new HealthReportData([$failure], []))->exitCodeFor(SeverityEnum::High))->toBe(ExitCodeEnum::NoFindingsAtOrAboveFailOn)
        ->and((new HealthReportData([$failure], []))->exitCodeFor(SeverityEnum::High, true))->toBe(ExitCodeEnum::DataSourceFailure)
        ->and((new HealthReportData([$failure], []))->exitCodeFor(null, true))->toBe(ExitCodeEnum::DataSourceFailure)
        ->and((new HealthReportData([$failure, $critical], []))->exitCodeFor(SeverityEnum::High, true))->toBe(ExitCodeEnum::FindingsAtOrAboveFailOn)
        ->and((new HealthReportData([$deliberate, $offline], []))->exitCodeFor(SeverityEnum::High, true))->toBe(ExitCodeEnum::NoFindingsAtOrAboveFailOn)
        ->and((new HealthReportData([$failure, $deliberate], []))->dataSourceFailureMessage())
        ->toBe("--strict: checks were skipped because a data source failed:\n  php-eol (php): endoflife.date did not respond.");
});

it('drops a finding covered by an active ignore rule and keeps an expired one', function () {
    $active = finding(FindingStatusEnum::Fail, SeverityEnum::Critical, 'vendor/package', 'GHSA-xxxx', 'composer-advisories');
    $expired = finding(FindingStatusEnum::Fail, SeverityEnum::Critical, 'other/package', 'GHSA-yyyy', 'composer-advisories');
    $report = new HealthReportData(
        checkResults: [
            CheckResultData::fromFindings('composer-advisories', 'Known vulnerabilities (Composer)', [$active, $expired]),
        ],
        upgradeSteps: [],
    );
    $configuration = new ExcubiaConfigurationData(
        failOnSeverity: SeverityEnum::High,
        isDevelopmentDependencyIncluded: true,
        staleAfterMonths: 24,
        phpWarnDaysBeforeEndOfLife: 180,
        laravelWarnDaysBeforeEndOfLife: 180,
        isNodeEndOfLifeCheckEnabled: true,
        ignoreRules: [
            [
                'checkId' => 'composer-advisories',
                'subject' => 'vendor/package',
                'advisoryId' => 'GHSA-xxxx',
                'reason' => 'Not reachable',
                'until' => '2027-01-31',
            ],
            [
                'checkId' => 'composer-advisories',
                'subject' => 'other/package',
                'advisoryId' => 'GHSA-yyyy',
                'reason' => 'Waiting on upstream',
                'until' => '2026-01-01',
            ],
        ],
        cacheDirectory: null,
        cacheTtlHours: 24,
    );
    $today = new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC'));

    $filtered = $report->withoutIgnoredFindings($configuration, $today);

    expect($filtered->findings())->toHaveCount(1)
        ->and($filtered->findings()[0]->subject)->toBe('other/package')
        ->and($filtered->exitCodeFor(SeverityEnum::High))->toBe(ExitCodeEnum::FindingsAtOrAboveFailOn)
        ->and($configuration->expiredIgnoreWarnings($report->findings(), $today))->toBe([
            'Ignore rule for composer-advisories on other/package expired on 2026-01-01. The finding is active again. Reason was: Waiting on upstream',
        ]);
});

it('rolls a check up to fail when any finding failed', function () {
    $result = CheckResultData::fromFindings('php-eol', 'PHP end of life', [
        finding(FindingStatusEnum::Warn, SeverityEnum::Medium),
        finding(FindingStatusEnum::Fail, SeverityEnum::Critical),
    ]);

    expect($result->status)->toBe(FindingStatusEnum::Fail);
});
