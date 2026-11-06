<?php

declare(strict_types=1);

use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Tests\Support\FakeHttpClient;
use Symfony\Component\Console\Tester\CommandTester;

const POSTURE_CHECK_IDS = [
    'laravel-debug-mode',
    'laravel-production-caches',
    'laravel-pending-migrations',
    'laravel-failed-jobs',
    'laravel-scheduler-heartbeat',
    'laravel-maintenance-mode',
    'laravel-app-key',
    'laravel-session-security',
    'laravel-cors-wildcard',
    'laravel-production-drivers',
    'laravel-log-level',
    'laravel-dev-packages',
    'laravel-database',
    'laravel-cache',
    'laravel-storage',
    'laravel-disk-space',
    'laravel-queue-backlog',
    'laravel-queue-workers',
    'laravel-backups',
    'laravel-database-connections',
    'laravel-database-size',
    'php-opcache',
    'php-production-ini',
];

/**
 * `excubia check --format=json` on a project with no packages, so no data
 * source is asked and only the posture checks matter.
 *
 * @param  array<string, mixed>  $options
 * @return array{exitCode: int, report: array<string, mixed>}
 */
function standalonePostureReport(array $options = []): array
{
    $project = temporaryProject([
        'composer.json' => '{}',
        'composer.lock' => '{"packages": [], "packages-dev": []}',
    ]);
    $tester = new CommandTester(new CheckCommand(new FakeHttpClient([])));
    $exitCode = $tester->execute(['path' => $project, '--format' => 'json', '--no-banner' => true, '--strict' => true] + $options);

    return ['exitCode' => $exitCode, 'report' => json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)];
}

it('skips all twenty-one posture checks with "requires Artisan" in the standalone CLI', function () {
    $scan = standalonePostureReport();
    $results = array_values(array_filter(
        $scan['report']['checks'],
        fn (array $check): bool => in_array($check['checkId'], POSTURE_CHECK_IDS, true),
    ));

    expect(array_column($results, 'checkId'))->toBe(POSTURE_CHECK_IDS)
        ->and($scan['exitCode'])->toBe(0);

    foreach ($results as $result) {
        expect($result['status'])->toBe('skipped')
            ->and($result['findings'])->toHaveCount(1)
            ->and($result['findings'][0]['message'])->toContain('requires Artisan')
            ->and($result['findings'][0]['skipReason'])->toBe('not-applicable');
    }
});

it('registers the posture checks after the existing checks and honours --skip', function () {
    $checkIds = array_column(standalonePostureReport()['report']['checks'], 'checkId');
    $skipped = array_column(standalonePostureReport(['--skip' => 'laravel-debug-mode,laravel-failed-jobs'])['report']['checks'], 'checkId');

    expect(array_slice($checkIds, -28))->toBe([...array_slice(POSTURE_CHECK_IDS, 0, 12), 'dependency-licenses', ...array_slice(POSTURE_CHECK_IDS, 12, 5), 'php-platform-requirements', 'env-example-sync', 'composer-outdated', 'laravel-queue-workers', 'npm-outdated', 'laravel-backups', 'laravel-database-connections', 'laravel-database-size', 'php-opcache', 'php-production-ini'])
        ->and(array_search('laravel-next-blocker', $checkIds, true))->toBeLessThan(array_search('laravel-debug-mode', $checkIds, true))
        ->and($skipped)->not->toContain('laravel-debug-mode', 'laravel-failed-jobs')
        ->and($skipped)->toContain('laravel-maintenance-mode');
});

it('runs php-platform-requirements in the standalone CLI against its own PHP', function () {
    $checks = array_column(standalonePostureReport()['report']['checks'], null, 'checkId');

    expect($checks['php-platform-requirements']['status'])->toBe('pass')
        ->and($checks['php-platform-requirements']['findings'])->toBe([]);
});
