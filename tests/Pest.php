<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Qiick\Excubia\Services\ReleaseService;
use Qiick\Excubia\Services\RuntimeBufferService;
use Qiick\Excubia\Tests\Support\LaravelTestCase;

// CI sets GITHUB_SHA (and a developer may set EXCUBIA_RELEASE or
// EXCUBIA_SERVER_NAME): clear them so reports and runtime configuration stay
// the same everywhere; the tests that need them set them.
foreach (['EXCUBIA_RELEASE', 'EXCUBIA_SERVER_NAME', ...ReleaseService::PLATFORM_VARIABLES] as $releaseVariable) {
    putenv($releaseVariable);
}

/**
 * @param  array<string, string>  $files
 */
function temporaryProject(array $files): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'excubia-'.bin2hex(random_bytes(4));

    if (! mkdir($root) && ! is_dir($root)) {
        throw new RuntimeException('Could not create a temporary project.');
    }

    foreach ($files as $name => $contents) {
        $path = $root.DIRECTORY_SEPARATOR.$name;
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create '.$directory.'.');
        }

        file_put_contents($path, $contents);
    }

    return $root;
}

/**
 * endoflife.date `php` cycles for an outdated project checked through
 * Artisan. Artisan reports the PHP that runs the tests (#273), so its cycle
 * is listed with security support ended, next to the project's declared
 * 8.1, and a supported cycle keeps a fix hint.
 *
 * @return list<array{cycle: string, eol: string, support: string, lts: bool}>
 */
function phpCyclesWithRunningPhpEnded(): array
{
    $endedCycle = ['eol' => '2025-12-31', 'support' => '2023-11-25', 'lts' => false];
    $cycles = [
        '8.1' => ['cycle' => '8.1', ...$endedCycle],
        PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION => ['cycle' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, ...$endedCycle],
        '9.9' => ['cycle' => '9.9', 'eol' => '2099-12-31', 'support' => '2098-12-31', 'lts' => false],
    ];

    return array_values($cycles);
}

/**
 * Failure message of the public contract snapshot tests (docs/CONTRACTS.md, #76).
 */
const CONTRACT_CHANGE_MESSAGE = 'A public contract changed (docs/CONTRACTS.md). If the change is deliberate: '
    .'update tests/Fixtures/Contracts/public-contracts.json, the table in docs/CONTRACTS.md and the CHANGELOG, '
    .'and follow docs/VERSIONING.md (a removed or changed contract waits for a major; additions are a minor).';

/**
 * One area of the committed public contract snapshot.
 *
 * @return array<string, mixed>|list<mixed>
 */
function contractSnapshot(string $area): array
{
    $snapshot = json_decode(
        (string) file_get_contents(__DIR__.'/Fixtures/Contracts/public-contracts.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $snapshot[$area];
}

/**
 * The aggregates written to the local buffer so far.
 *
 * @return list<array<string, mixed>>
 */
function bufferedRuntime(Application $app): array
{
    $path = $app->storagePath('excubia/runtime/'.RuntimeBufferService::BUFFER_FILE);

    if (! is_file($path)) {
        return [];
    }

    return array_map(
        fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", (string) file_get_contents($path)))),
    );
}

/**
 * The merged buffered aggregates of one type.
 *
 * @return Collection<int, array<string, mixed>>
 */
function bufferedOfType(Application $app, string $type): Collection
{
    return collect(RuntimeBufferService::merge(bufferedRuntime($app)))->where('type', $type)->values();
}

/**
 * Run an AI call whose tool fails, and return 1 when the run failed with the
 * tool's exception, 0 when it went on. laravel/ai 1.3 rethrows the tool's
 * exception; 1.4 returns the failure to the model, so both are supported.
 */
function runFailureCount(Closure $run): int
{
    try {
        $run();
    } catch (RuntimeException) {
        return 1;
    }

    return 0;
}

pest()->extend(LaravelTestCase::class)->in('Laravel');
