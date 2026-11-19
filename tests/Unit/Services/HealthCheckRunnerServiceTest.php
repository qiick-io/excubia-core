<?php

declare(strict_types=1);

use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\LaravelPostureData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\HealthCheckRunnerService;
use Qiick\Excubia\Services\ProjectSnapshotService;
use Qiick\Excubia\Tests\Support\FakeHttpClient;

it('runs the checks on a snapshot without a project directory', function (): void {
    $client = new FakeHttpClient([
        'https://endoflife.date/api/php.json' => [
            ['cycle' => '8.0', 'releaseDate' => '2020-11-26', 'support' => '2022-11-26', 'eol' => '2023-11-26'],
        ],
    ]);
    $runner = new HealthCheckRunnerService($client, new DateTimeImmutable('2026-10-31', new DateTimeZone('UTC')));

    $result = $runner->runForSnapshot(
        new ProjectSnapshotData(
            phpVersion: '8.0.30',
            laravelFrameworkVersion: null,
            composerPackages: [new LockedPackageData('vendor/package', '1.0.0', true, false)],
            npmPackages: [],
            nodeVersionDeclaration: null,
        ),
        ExcubiaConfigurationData::defaults(),
        ['php-eol'],
        null,
        false,
        true,
    );

    $findings = $result['report']->findings();

    expect($client->calls)->toBe(1)
        ->and($findings)->toHaveCount(1)
        ->and($findings[0])->toBeInstanceOf(FindingData::class)
        ->and($findings[0]->checkId)->toBe('php-eol')
        ->and($findings[0]->severity)->toBe(SeverityEnum::Critical);
});

/**
 * Runs `php-eol` on the `healthy-laravel-13` fixture (`config.platform.php`
 * 8.4.1) with PHP 8.5.1 injected as the running PHP, in the app (posture
 * attached, as the Artisan aliases do) or from outside (no posture).
 */
function phpCycleRun(bool $isInsideApplication): array
{
    $client = new FakeHttpClient([
        'https://endoflife.date/api/php.json' => [
            ['cycle' => '8.5', 'releaseDate' => '2025-11-20', 'support' => '2027-12-31', 'eol' => '2029-12-31'],
            ['cycle' => '8.4', 'releaseDate' => '2024-11-21', 'support' => '2026-12-31', 'eol' => '2028-12-31'],
        ],
    ]);
    $runner = new HealthCheckRunnerService(
        $client,
        new DateTimeImmutable('2026-11-17', new DateTimeZone('UTC')),
        new ProjectSnapshotService(runningPhpVersion: '8.5.1'),
    );

    $report = $runner->run(
        dirname(__DIR__, 2).'/Fixtures/Projects/healthy-laravel-13',
        ExcubiaConfigurationData::defaults(),
        ['php-eol'],
        null,
        false,
        true,
        $isInsideApplication ? new LaravelPostureData('production', false, true, true, true, false, [], 0, null, true, null, new DateTimeImmutable) : null,
    )['report'];

    return [
        'version' => $report->project?->php,
        'cycle' => $report->project?->phpCycle?->cycle,
        'securitySupportEndsOn' => $report->project?->phpCycle?->securitySupportEndsOn,
        'activeSupportEndsOn' => $report->project?->phpCycle?->activeSupportEndsOn,
    ];
}

it('reports the running PHP and its cycle for an in-app run (#273)', function (): void {
    expect(phpCycleRun(true))->toBe([
        'version' => '8.5.1',
        'cycle' => '8.5',
        'securitySupportEndsOn' => '2029-12-31',
        'activeSupportEndsOn' => '2027-12-31',
    ]);
});

it('keeps the composer PHP and its cycle for a run from outside the app (#273)', function (): void {
    expect(phpCycleRun(false))->toBe([
        'version' => '8.4.1',
        'cycle' => '8.4',
        'securitySupportEndsOn' => '2028-12-31',
        'activeSupportEndsOn' => '2026-12-31',
    ]);
});
