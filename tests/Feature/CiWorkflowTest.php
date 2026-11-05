<?php

declare(strict_types=1);

it('keeps Pest on PHP 8.3 and newer and does not call the reusable workflow', function () {
    $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');

    expect($workflow)->toContain("- '8.3'")
        ->and($workflow)->toContain("- '8.4'")
        ->and($workflow)->toContain("- '8.5'")
        ->and($workflow)->not->toContain('8.2')
        ->and($workflow)->not->toContain('qiick-io/.github')
        ->and($workflow)->toContain('actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1')
        ->and($workflow)->toContain('shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962')
        ->and($workflow)->toContain('--prefer-lowest')
        ->and($workflow)->toContain('php .github/assert-coverage.php build/clover.xml src/Checks=90 src=90');
});

it('runs the Laravel integration tests on Laravel 12 and 13', function () {
    $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');

    expect($workflow)->toContain('laravel/framework:^${{ matrix.laravel }}.0')
        ->and($workflow)->toContain("testbench: '10'")
        ->and($workflow)->toContain("testbench: '11'")
        ->and($workflow)->toContain('vendor/bin/pest tests/Laravel');
});

it('passes the checks coverage gate when no check class exists', function () {
    $emptyDirectory = sys_get_temp_dir().'/excubia-empty-checks-'.bin2hex(random_bytes(4));
    mkdir($emptyDirectory);
    [$exitCode, $output] = runCoverageGate('/does/not/exist.xml', $emptyDirectory, ['src/Checks=90']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('not applied');
});

it('fails the checks coverage gate below 90 percent', function () {
    $rootDirectory = temporaryProjectDirectory();
    $clover = cloverReport([
        'src/Checks/ExampleCheck.php' => [1, 0, 0],
    ]);

    [$exitCode, $output] = runCoverageGate($clover, $rootDirectory, ['src/Checks=90']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('below 90%');
});

it('passes the checks coverage gate at 90 percent or more', function () {
    $rootDirectory = temporaryProjectDirectory();
    $clover = cloverReport([
        'src/Checks/ExampleCheck.php' => [1, 1, 0, 1, 1, 1, 1, 1, 1, 1],
    ]);

    [$exitCode] = runCoverageGate($clover, $rootDirectory, ['src/Checks=90']);

    expect($exitCode)->toBe(0);
});

it('fails the whole src gate even when src/Checks passes', function () {
    $rootDirectory = temporaryProjectDirectory();
    $clover = cloverReport([
        'src/Checks/ExampleCheck.php' => [1, 1, 1, 1, 1, 1, 1, 1, 1, 1],
        'src/Services/ExampleService.php' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
    ]);

    [$exitCode, $output] = runCoverageGate($clover, $rootDirectory, ['src/Checks=90', 'src=85']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('src/Checks coverage is 100.0% (10/10 lines), threshold 90%: OK.')
        ->and($output)->toContain('src coverage is 50.0% (10/20 lines), threshold 85%: FAIL, below 85%.');
});

it('passes every path gate and prints one summary line per path', function () {
    $rootDirectory = temporaryProjectDirectory();
    $clover = cloverReport([
        'src/Checks/ExampleCheck.php' => [1, 1, 1, 1, 1, 1, 1, 1, 1, 1],
        'src/Services/ExampleService.php' => [1, 1, 1, 1, 1, 1, 1, 1, 0, 0],
    ]);

    [$exitCode, $output] = runCoverageGate($clover, $rootDirectory, ['src/Checks=90', 'src=85']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('src/Checks coverage is 100.0% (10/10 lines), threshold 90%: OK.')
        ->and($output)->toContain('src coverage is 90.0% (18/20 lines), threshold 85%: OK.');
});

it('fails the coverage gate when a path has code but no lines in the report', function () {
    $rootDirectory = temporaryProjectDirectory();
    $clover = cloverReport([
        'lib/Other.php' => [1],
    ]);

    [$exitCode, $output] = runCoverageGate($clover, $rootDirectory, ['src/Checks=90']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('no executable lines');
});

it('rejects a coverage gate call without thresholds or with a malformed one', function () {
    $rootDirectory = temporaryProjectDirectory();
    $clover = cloverReport([
        'src/Checks/ExampleCheck.php' => [1],
    ]);

    [$missingExitCode, $missingOutput] = runCoverageGate($clover, $rootDirectory, []);
    [$malformedExitCode, $malformedOutput] = runCoverageGate($clover, $rootDirectory, ['src/Checks=90', 'src=high']);

    expect($missingExitCode)->toBe(1)
        ->and($missingOutput)->toContain('Usage:')
        ->and($malformedExitCode)->toBe(1)
        ->and($malformedOutput)->toContain('Unrecognised argument "src=high"');
});

function temporaryProjectDirectory(): string
{
    $directory = sys_get_temp_dir().'/excubia-project-'.bin2hex(random_bytes(4));
    mkdir($directory.'/src/Checks', 0777, true);
    file_put_contents($directory.'/src/Checks/ExampleCheck.php', "<?php\ndeclare(strict_types=1);\n");

    return $directory;
}

/**
 * Writes a Clover report with one file per entry and one statement per count.
 *
 * @param  array<string, list<int>>  $files  relative file path => hit count per statement
 */
function cloverReport(array $files): string
{
    $fileElements = '';

    foreach ($files as $relativePath => $counts) {
        $rows = '';

        foreach ($counts as $index => $count) {
            $rows .= '<line num="'.($index + 1).'" type="stmt" count="'.$count.'"/>';
        }

        $fileElements .= '<file name="/tmp/project/'.$relativePath.'">'.$rows.'</file>';
    }

    $path = sys_get_temp_dir().'/excubia-clover-'.bin2hex(random_bytes(4)).'.xml';
    file_put_contents($path, '<?xml version="1.0"?><coverage><project>'.$fileElements.'</project></coverage>');

    return $path;
}

/**
 * @param  list<string>  $thresholds  path=percent arguments
 * @return array{0: int, 1: string}
 */
function runCoverageGate(string $cloverPath, string $rootDirectory, array $thresholds): array
{
    $script = dirname(__DIR__, 2).'/.github/assert-coverage.php';
    $arguments = array_map('escapeshellarg', [$cloverPath, ...$thresholds, '--root='.$rootDirectory]);
    $command = 'php '.escapeshellarg($script).' '.implode(' ', $arguments);
    exec($command.' 2>&1', $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}
