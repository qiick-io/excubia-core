<?php

declare(strict_types=1);

/**
 * Enforces the spec coverage gate (docs/SPEC.md section 8): line coverage per
 * path prefix, read from a Clover report.
 *
 * Usage: php .github/assert-coverage.php <clover.xml> <path>=<percent>... [--root=<directory>]
 * Example: php .github/assert-coverage.php build/clover.xml src/Checks=90 src=90
 *
 * Each path counts the statements of the report files that live under it, so
 * `src` includes `src/Checks`. A path with no PHP file under the root yet is not
 * applied, so a new directory does not fail the build before it has code. The
 * script prints one summary line per path and exits 1 when any path is below
 * its threshold or the report is missing or unreadable.
 */

$arguments = array_slice($argv, 1);
$cloverPath = '';
$rootDirectory = dirname(__DIR__);

/** @var array<string, float> $thresholds */
$thresholds = [];

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--root=')) {
        $rootDirectory = substr($argument, strlen('--root='));

        continue;
    }

    if (preg_match('/^([A-Za-z0-9_.\/-]+)=(\d+(?:\.\d+)?)$/', $argument, $matches) === 1) {
        $thresholds[trim($matches[1], '/')] = (float) $matches[2];

        continue;
    }

    if ($cloverPath === '') {
        $cloverPath = $argument;

        continue;
    }

    fwrite(STDERR, sprintf("Unrecognised argument \"%s\". Expected <path>=<percent>.\n", $argument));

    exit(1);
}

if ($thresholds === []) {
    fwrite(STDERR, "Usage: php .github/assert-coverage.php <clover.xml> <path>=<percent>... [--root=<directory>]\n");

    exit(1);
}

/** @var array<string, float> $appliedThresholds */
$appliedThresholds = [];

foreach ($thresholds as $path => $threshold) {
    if (hasPhpFiles($rootDirectory.'/'.$path)) {
        $appliedThresholds[$path] = $threshold;

        continue;
    }

    fwrite(STDOUT, sprintf("%s has no PHP files yet. The %s coverage gate is not applied.\n", $path, $path));
}

if ($appliedThresholds === []) {
    exit(0);
}

if ($cloverPath === '' || ! is_file($cloverPath)) {
    fwrite(STDERR, "Clover report was not found.\n");

    exit(1);
}

$report = @simplexml_load_file($cloverPath);

if ($report === false) {
    fwrite(STDERR, "Clover report could not be read.\n");

    exit(1);
}

/** @var array<string, array{covered: int, executable: int}> $totals */
$totals = array_fill_keys(array_keys($appliedThresholds), ['covered' => 0, 'executable' => 0]);

foreach ($report->xpath('//file') ?: [] as $file) {
    $filePath = str_replace('\\', '/', (string) $file['name']);
    $matchingPaths = array_filter(
        array_keys($totals),
        fn (string $path): bool => str_contains($filePath, '/'.$path.'/'),
    );

    if ($matchingPaths === []) {
        continue;
    }

    foreach ($file->line as $line) {
        if ((string) $line['type'] !== 'stmt') {
            continue;
        }

        $isCovered = (int) $line['count'] > 0;

        foreach ($matchingPaths as $path) {
            $totals[$path]['executable']++;

            if ($isCovered) {
                $totals[$path]['covered']++;
            }
        }
    }
}

$hasFailure = false;

foreach ($appliedThresholds as $path => $threshold) {
    ['covered' => $covered, 'executable' => $executable] = $totals[$path];

    if ($executable === 0) {
        fwrite(STDERR, sprintf("%s has PHP files but the coverage report has no executable lines for them.\n", $path));
        $hasFailure = true;

        continue;
    }

    $percentage = ($covered / $executable) * 100;
    $summary = sprintf('%s coverage is %.1f%% (%d/%d lines), threshold %s%%', $path, $percentage, $covered, $executable, $threshold);

    if ($percentage < $threshold) {
        fwrite(STDERR, sprintf("%s: FAIL, below %s%%.\n", $summary, $threshold));
        $hasFailure = true;

        continue;
    }

    fwrite(STDOUT, $summary.": OK.\n");
}

exit($hasFailure ? 1 : 0);

/**
 * Whether the directory holds at least one PHP file at any depth.
 */
function hasPhpFiles(string $directory): bool
{
    if (! is_dir($directory)) {
        return false;
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            return true;
        }
    }

    return false;
}
