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

pest()->extend(LaravelTestCase::class)->in('Laravel');
