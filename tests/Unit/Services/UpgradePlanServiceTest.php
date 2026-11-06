<?php

declare(strict_types=1);

use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\HealthReportData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\ExitCodeEnum;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\UpgradePlanService;

it('orders security, PHP, blockers, the next Laravel major, then replacements', function () {
    $snapshot = new ProjectSnapshotData('8.2.0.0', 'v10.48.22', [], [], null);
    $findings = [
        planFinding('composer-abandoned', FindingStatusEnum::Warn, 'Replace with symfony/mailer.'),
        planFinding('laravel-eol', FindingStatusEnum::Fail, 'Security fixes for your version end 2025-02-04.'),
        planFinding('php-eol', FindingStatusEnum::Fail, 'Upgrade PHP to 8.3 (supported until 2027-12-31).'),
        planFinding('composer-advisories', FindingStatusEnum::Fail, 'Update vendor/package to ≥ fixed version (GHSA-xxxx).'),
        planFinding('laravel-next-blocker', FindingStatusEnum::Warn, 'No release supports the next major yet.'),
        planFinding('node-eol', FindingStatusEnum::Fail, 'Move to Node LTS 22.'),
        planFinding('composer-advisories', FindingStatusEnum::Pass, 'No advisories.'),
    ];

    expect(planMessages($snapshot, $findings))->toBe([
        '1. Update vendor/package to ≥ fixed version (GHSA-xxxx).',
        '2. Upgrade PHP to 8.3 (supported until 2027-12-31).',
        '3. Laravel 11 is blocked by subject; no release supports it yet: watch, replace or fork.',
        '4. Security fixes for your version end 2025-02-04.',
        '5. subject is abandoned. Replace with symfony/mailer.',
    ]);
});

it('collapses several advisories for one package into one step', function () {
    $findings = [
        planFinding('composer-advisories', FindingStatusEnum::Fail, 'Update laravel/framework to ≥ fixed version (PKSA-1).', 'laravel/framework', 'PKSA-1'),
        planFinding('composer-advisories', FindingStatusEnum::Fail, 'Update guzzlehttp/psr7 to ≥ fixed version (GHSA-1).', 'guzzlehttp/psr7', 'GHSA-1'),
        planFinding('composer-advisories', FindingStatusEnum::Warn, 'Update laravel/framework to ≥ fixed version (PKSA-2).', 'laravel/framework', 'PKSA-2'),
        planFinding('composer-advisories', FindingStatusEnum::Fail, 'Update laravel/framework to ≥ fixed version (PKSA-3).', 'laravel/framework', 'PKSA-3'),
        planFinding('npm-advisories', FindingStatusEnum::Fail, 'Update axios to ≥ fixed version.', 'axios', 'GHSA-a'),
        planFinding('npm-advisories', FindingStatusEnum::Fail, 'Update axios to ≥ fixed version.', 'axios', 'GHSA-b'),
    ];

    expect(planMessages(new ProjectSnapshotData(null, 'v10.48.22', [], [], null), $findings))->toBe([
        '1. Update laravel/framework to ≥ fixed version (fixes 3 advisories).',
        '2. Update guzzlehttp/psr7 to ≥ fixed version (GHSA-1).',
        '3. Update axios to ≥ fixed version (fixes 2 advisories).',
    ]);
});

it('names every next-major blocker in one step', function () {
    $findings = [
        planFinding('laravel-next-blocker', FindingStatusEnum::Warn, 'No release supports Laravel 14 yet; watch / replace / fork.', 'spatie/laravel-data'),
        planFinding('laravel-next-blocker', FindingStatusEnum::Warn, 'No release supports Laravel 14 yet; watch / replace / fork.', 'livewire/livewire'),
        planFinding('laravel-next-blocker', FindingStatusEnum::Warn, 'No release supports Laravel 14 yet; watch / replace / fork.', 'laravel/horizon'),
    ];

    expect(planMessages(new ProjectSnapshotData(null, 'v13.2.0', [], [], null), $findings))->toBe([
        '1. Laravel 14 is blocked by spatie/laravel-data, livewire/livewire, laravel/horizon; no release supports it yet: watch, replace or fork.',
    ])->and(planMessages(new ProjectSnapshotData(null, null, [], [], null), $findings))->toBe([
        '1. The next Laravel major is blocked by spatie/laravel-data, livewire/livewire, laravel/horizon; no release supports it yet: watch, replace or fork.',
    ]);
});

it('keeps the Laravel end-of-life hint without repeating the target', function () {
    $findings = [
        planFinding('laravel-eol', FindingStatusEnum::Fail, 'Upgrade to Laravel 11; security fixes for your version end 2025-02-04.', 'laravel/framework'),
    ];

    expect(planMessages(new ProjectSnapshotData(null, 'v10.48.22', [], [], null), $findings))->toBe([
        '1. Upgrade to Laravel 11; security fixes for your version end 2025-02-04.',
    ]);
});

it('gives each abandoned package a step and groups stale packages', function () {
    $findings = [
        planFinding('composer-stale', FindingStatusEnum::Warn, 'Consider a maintained alternative.', 'vendor/stale-one'),
        planFinding('composer-abandoned', FindingStatusEnum::Fail, 'Replace with symfony/mailer.', 'swiftmailer/swiftmailer'),
        planFinding('composer-stale', FindingStatusEnum::Fail, 'Consider a maintained alternative.', 'vendor/archived'),
        planFinding('composer-abandoned', FindingStatusEnum::Warn, 'no replacement suggested', 'vendor/orphan'),
        planFinding('composer-stale', FindingStatusEnum::Warn, 'Consider a maintained alternative.', 'swiftmailer/swiftmailer'),
    ];

    expect(planMessages(new ProjectSnapshotData(null, 'v12.0.0', [], [], null), $findings))->toBe([
        '1. swiftmailer/swiftmailer is abandoned. Replace with symfony/mailer.',
        '2. vendor/orphan is abandoned. No replacement suggested.',
        '3. Consider maintained alternatives to stale or archived packages: vendor/stale-one, vendor/archived.',
    ]);
});

it('drops identical steps', function () {
    $findings = [
        planFinding('php-eol', FindingStatusEnum::Fail, 'Upgrade PHP to 8.5 (supported until 2029-12-31).', 'php'),
        planFinding('php-eol', FindingStatusEnum::Warn, 'Upgrade PHP to 8.5 (supported until 2029-12-31).', 'php'),
    ];

    expect(planMessages(new ProjectSnapshotData(null, null, [], [], null), $findings))->toBe([
        '1. Upgrade PHP to 8.5 (supported until 2029-12-31).',
    ]);
});

it('uses the locked major plus one rather than a fixed Laravel 13', function () {
    $service = new UpgradePlanService;

    expect($service->nextLaravelMajor('v12.40.0'))->toBe(13)
        ->and($service->nextLaravelMajor('v13.39.0'))->toBe(14)
        ->and($service->nextLaravelMajor(null))->toBeNull();
});

it('ends with one latest-patch step for outdated packages without an advisory', function () {
    $outdated = static fn (string $package, ?string $latestPatch, FindingStatusEnum $status = FindingStatusEnum::Warn): FindingData => new FindingData(
        checkId: 'composer-outdated',
        severity: SeverityEnum::Info,
        status: $status,
        subject: $package,
        message: 'Behind.',
        fixHint: 'Update.',
        evidenceUrl: null,
        details: ['package' => $package, 'lockedVersion' => '1.0.0', 'latestPatch' => $latestPatch, 'latestMinor' => null, 'latestMajor' => null, 'isMajorBehind' => false],
    );
    $snapshot = new ProjectSnapshotData(null, null, [], [], null);

    expect(planMessages($snapshot, [
        $outdated('vendor/patched', '1.0.3'),
        $outdated('vendor/vulnerable', '1.0.5'),
        $outdated('vendor/minor-only', null),
        $outdated('vendor/ignored', '1.0.1', FindingStatusEnum::Pass),
        $outdated('vendor/second', 'v2.4.9'),
        planFinding('composer-abandoned', FindingStatusEnum::Warn, 'Replace with vendor/other.', 'vendor/old'),
        planFinding('composer-advisories', FindingStatusEnum::Fail, 'Update vendor/vulnerable to 1.0.5.', 'vendor/vulnerable', 'GHSA-1'),
    ]))->toBe([
        '1. Update vendor/vulnerable to 1.0.5.',
        '2. vendor/old is abandoned. Replace with vendor/other.',
        '3. Update to the latest patch: vendor/patched 1.0.3, vendor/second v2.4.9.',
    ])
        ->and(planMessages($snapshot, [$outdated('vendor/minor-only', null)]))->toBe([]);
});

it('does not change the exit code', function () {
    $steps = (new UpgradePlanService)->plan(
        new ProjectSnapshotData(null, 'v12.0.0', [], [], null),
        [planFinding('php-eol', FindingStatusEnum::Fail, 'Upgrade PHP.')],
    );
    $report = new HealthReportData([], $steps);

    expect($report->exitCodeFor(SeverityEnum::High))->toBe(ExitCodeEnum::NoFindingsAtOrAboveFailOn);
});

function planFinding(string $checkId, FindingStatusEnum $status, string $fixHint, string $subject = 'subject', ?string $advisoryId = null): FindingData
{
    return new FindingData(
        checkId: $checkId,
        severity: SeverityEnum::High,
        status: $status,
        subject: $subject,
        message: 'Message.',
        fixHint: $fixHint,
        evidenceUrl: null,
        advisoryId: $advisoryId,
    );
}

/**
 * @param  list<FindingData>  $findings
 * @return list<string>
 */
function planMessages(ProjectSnapshotData $snapshot, array $findings): array
{
    return array_map(
        static fn ($step): string => $step->stepNumber.'. '.$step->message,
        (new UpgradePlanService)->plan($snapshot, $findings),
    );
}
