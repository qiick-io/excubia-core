<?php

declare(strict_types=1);

use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\HealthReportData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Enums\SkipReasonEnum;
use Qiick\Excubia\Renderers\OhDearHealthRenderer;

function healthFinding(string $checkId, FindingStatusEnum $status, SeverityEnum $severity, string $subject, string $message = 'Found.'): FindingData
{
    return new FindingData(
        checkId: $checkId,
        severity: $severity,
        status: $status,
        subject: $subject,
        message: $message,
        fixHint: 'Update it.',
        evidenceUrl: 'https://example.test/advisory',
    );
}

/**
 * @return array{finishedAt: int, checkResults: list<array<string, mixed>>}
 */
function ohDearPayload(string $json): array
{
    /** @var array{finishedAt: int, checkResults: list<array<string, mixed>>} */
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

$finishedAt = new DateTimeImmutable('2026-11-03 10:00:00', new DateTimeZone('UTC'));

it('maps pass, warn, fail and skipped to the Oh Dear statuses', function () use ($finishedAt) {
    $report = new HealthReportData([
        CheckResultData::fromFindings('php-eol', 'PHP end of life', []),
        CheckResultData::fromFindings('composer-stale', 'Stale packages', [
            healthFinding('composer-stale', FindingStatusEnum::Warn, SeverityEnum::Low, 'vendor/old'),
        ]),
        CheckResultData::fromFindings('composer-advisories', 'Known vulnerabilities (Composer)', [
            healthFinding('composer-advisories', FindingStatusEnum::Fail, SeverityEnum::High, 'vendor/a'),
            healthFinding('composer-advisories', FindingStatusEnum::Fail, SeverityEnum::Critical, 'vendor/b'),
        ]),
        CheckResultData::skipped('laravel-eol', 'Laravel end of life', 'laravel/framework', 'Not locked.'),
    ], []);

    $payload = ohDearPayload((new OhDearHealthRenderer($finishedAt))->render($report));

    expect($payload['finishedAt'])->toBe($finishedAt->getTimestamp())
        ->and(array_column($payload['checkResults'], 'status', 'name'))->toBe([
            'php-eol' => 'ok',
            'composer-stale' => 'warning',
            'composer-advisories' => 'failed',
            'laravel-eol' => 'skipped',
        ])
        ->and($payload['checkResults'][0])->toBe([
            'name' => 'php-eol',
            'label' => 'PHP end of life',
            'status' => 'ok',
            'notificationMessage' => '',
            'shortSummary' => 'OK',
            'meta' => ['findingCount' => 0],
        ])
        ->and($payload['checkResults'][2]['shortSummary'])->toBe('2 findings')
        ->and($payload['checkResults'][2]['notificationMessage'])->toBe('2 findings (highest severity: critical): vendor/a, vendor/b.')
        ->and($payload['checkResults'][2]['meta'])->toBe(['findingCount' => 2, 'highestSeverity' => 'critical'])
        ->and($payload['checkResults'][3]['notificationMessage'])->toBe('Skipped: the check does not apply to this project.');
});

it('names the skip reason and caps the listed subjects', function () use ($finishedAt) {
    $findings = [];

    foreach (range(1, 7) as $number) {
        $findings[] = healthFinding('composer-abandoned', FindingStatusEnum::Warn, SeverityEnum::Medium, 'vendor/package-'.$number);
    }

    $report = new HealthReportData([
        CheckResultData::fromFindings('composer-abandoned', 'Abandoned packages', $findings),
        CheckResultData::skipped('npm-advisories', 'Known vulnerabilities (npm)', 'npm-advisories', 'api.osv.dev timed out', SkipReasonEnum::DataSourceFailure),
        CheckResultData::skipped('node-eol', 'Node end of life', 'node-eol', 'No cache', SkipReasonEnum::Offline),
    ], []);

    $checkResults = ohDearPayload((new OhDearHealthRenderer($finishedAt))->render($report))['checkResults'];

    expect($checkResults[0]['notificationMessage'])->toEndWith('vendor/package-5 and 2 more.')
        ->and($checkResults[1]['notificationMessage'])->toBe('Skipped: a data source did not respond.')
        ->and($checkResults[2]['notificationMessage'])->toBe('Skipped: no cached data for an offline run.');
});

it('never copies finding text, so paths and hostnames stay out', function () use ($finishedAt) {
    $report = new HealthReportData([
        CheckResultData::fromFindings('node-eol', 'Node end of life', [
            healthFinding('node-eol', FindingStatusEnum::Fail, SeverityEnum::High, 'node', '/srv/app/.nvmrc on app.internal.example says 16'),
        ]),
    ], []);

    $json = (new OhDearHealthRenderer($finishedAt))->render($report);

    expect($json)->not->toContain('/srv/app')
        ->and($json)->not->toContain('internal.example')
        ->and($json)->not->toContain('https://');
});

it('renders a crashed run and a missing result as one entry', function (string $method, string $status) use ($finishedAt) {
    $payload = ohDearPayload((new OhDearHealthRenderer($finishedAt))->{$method}());

    expect($payload['checkResults'])->toHaveCount(1)
        ->and($payload['checkResults'][0]['name'])->toBe('excubia-run')
        ->and($payload['checkResults'][0]['status'])->toBe($status)
        ->and(array_keys($payload['checkResults'][0]))->toBe(['name', 'label', 'status', 'notificationMessage', 'shortSummary', 'meta']);
})->with([
    'crashed' => ['renderCrashed', 'crashed'],
    'not run yet' => ['renderNotRunYet', 'skipped'],
]);
