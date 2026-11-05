<?php

declare(strict_types=1);

use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\HealthCheckRunnerService;
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
