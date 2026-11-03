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
        ->and($workflow)->toContain('assert-checks-coverage.php');
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
    [$exitCode, $output] = runCoverageGate('/does/not/exist.xml', $emptyDirectory);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('not applied');
});

it('fails the checks coverage gate below 90 percent', function () {
    $checksDirectory = temporaryChecksDirectory();
    $clover = cloverReport([
        ['count' => 1],
        ['count' => 0],
        ['count' => 0],
    ]);

    [$exitCode, $output] = runCoverageGate($clover, $checksDirectory);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('below 90%');
});

it('passes the checks coverage gate at 90 percent or more', function () {
    $checksDirectory = temporaryChecksDirectory();
    $clover = cloverReport([
        ['count' => 1],
        ['count' => 1],
        ['count' => 0],
        ['count' => 1],
        ['count' => 1],
        ['count' => 1],
        ['count' => 1],
        ['count' => 1],
        ['count' => 1],
        ['count' => 1],
    ]);

    [$exitCode] = runCoverageGate($clover, $checksDirectory);

    expect($exitCode)->toBe(0);
});

function temporaryChecksDirectory(): string
{
    $directory = sys_get_temp_dir().'/excubia-checks-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/ExampleCheck.php', "<?php\ndeclare(strict_types=1);\n");

    return $directory;
}

/**
 * @param  list<array{count: int}>  $lines
 */
function cloverReport(array $lines): string
{
    $rows = '';

    foreach ($lines as $index => $line) {
        $number = $index + 1;
        $rows .= '<line num="'.$number.'" type="stmt" count="'.$line['count'].'"/>';
    }

    $path = sys_get_temp_dir().'/excubia-clover-'.bin2hex(random_bytes(4)).'.xml';
    file_put_contents($path, '<?xml version="1.0"?><coverage><project><file name="/tmp/src/Checks/ExampleCheck.php">'.$rows.'</file></project></coverage>');

    return $path;
}

/**
 * @return array{0: int, 1: string}
 */
function runCoverageGate(string $cloverPath, string $checksDirectory): array
{
    $script = dirname(__DIR__, 2).'/.github/assert-checks-coverage.php';
    $command = 'php '.escapeshellarg($script).' '.escapeshellarg($cloverPath).' '.escapeshellarg($checksDirectory);
    exec($command.' 2>&1', $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}
