<?php

declare(strict_types=1);

use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\HealthReportData;
use Qiick\Excubia\Data\UpgradeStepData;
use Qiick\Excubia\Data\UpgradeStepPackageData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Enums\UpgradeStepKindEnum;
use Qiick\Excubia\Renderers\JsonReportRenderer;
use Qiick\Excubia\Renderers\MarkdownReportRenderer;
use Qiick\Excubia\Renderers\TableReportRenderer;

it('prints the spec summary line', function () {
    $findings = [];

    foreach ([
        [3, SeverityEnum::Critical],
        [2, SeverityEnum::High],
        [5, SeverityEnum::Medium],
        [1, SeverityEnum::Low],
    ] as [$count, $severity]) {
        for ($index = 0; $index < $count; $index++) {
            $findings[] = new FindingData(
                checkId: 'php-eol',
                severity: $severity,
                status: FindingStatusEnum::Fail,
                subject: 'php',
                message: 'Finding.',
                fixHint: 'Upgrade.',
                evidenceUrl: null,
                advisoryId: 'GHSA-hidden',
            );
        }
    }

    $report = new HealthReportData([
        CheckResultData::fromFindings('php-eol', 'PHP end of life', $findings),
    ], []);

    expect($report->summaryLine())->toBe('3 critical · 2 high · 5 medium · 1 low');
});

it('matches the table, json, and markdown golden files', function () {
    $report = new HealthReportData(
        checkResults: [
            CheckResultData::fromFindings('php-eol', 'PHP end of life', [
                new FindingData('php-eol', SeverityEnum::Critical, FindingStatusEnum::Fail, 'php', 'Security support ended.', 'Upgrade PHP to 8.3 (supported until 2027-12-31).', null, 'GHSA-hidden'),
                new FindingData('php-eol', SeverityEnum::Info, FindingStatusEnum::Pass, 'php', 'Supported.', '', null),
            ]),
            CheckResultData::fromFindings('composer-advisories', 'Known vulnerabilities (Composer)', [
                new FindingData('composer-advisories', SeverityEnum::High, FindingStatusEnum::Warn, 'vendor/package', 'Known vulnerability.', 'Update vendor/package.', 'https://example.test/advisory'),
            ]),
        ],
        upgradeSteps: [
            new UpgradeStepData(1, 'Upgrade PHP first.', UpgradeStepKindEnum::PhpEol, null, [new UpgradeStepPackageData('php', 'composer', '8.1.0', null)]),
        ],
    );

    expect((new TableReportRenderer)->render($report))->toBe(file_get_contents(dirname(__DIR__).'/Fixtures/Reports/summary.txt'))
        ->and((new JsonReportRenderer)->render($report))->toBe(file_get_contents(dirname(__DIR__).'/Fixtures/Reports/summary.json'))
        ->and((new MarkdownReportRenderer)->render($report))->toBe(file_get_contents(dirname(__DIR__).'/Fixtures/Reports/summary.md'));
});

it('colours a severity heading only when asked', function () {
    $report = new HealthReportData([
        CheckResultData::fromFindings('php-eol', 'PHP end of life', [
            new FindingData('php-eol', SeverityEnum::Critical, FindingStatusEnum::Fail, 'php', 'Security support ended.', 'Upgrade.', null),
        ]),
    ], []);

    expect((new TableReportRenderer(true))->render($report))->toContain("\033[31mCritical\033[0m")
        ->and((new TableReportRenderer(false))->render($report))->not->toContain("\033[");
});
