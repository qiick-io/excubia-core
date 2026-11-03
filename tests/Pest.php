<?php

declare(strict_types=1);

use Qiick\Excubia\Tests\Support\LaravelTestCase;

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

pest()->extend(LaravelTestCase::class)->in('Laravel');
