<?php

declare(strict_types=1);

use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Console\ClearCacheCommand;
use Qiick\Excubia\Console\PushCommand;
use Qiick\Excubia\Laravel\ExcubiaCheckCommand;
use Qiick\Excubia\Laravel\ExcubiaHeartbeatCommand;
use Qiick\Excubia\Laravel\ExcubiaPushCommand;
use Qiick\Excubia\Laravel\ExcubiaPushRuntimeCommand;
use Symfony\Component\Console\Command\Command;

/*
 * Every user-facing name in the code must appear in the user docs
 * (README.md and docs/guides/*.md): CLI and Artisan options, Artisan
 * command names, check ids, excubia.json keys and environment variables.
 */

function userDocumentation(): string
{
    $root = dirname(__DIR__, 2);
    $documentation = (string) file_get_contents($root.'/README.md');

    foreach (glob($root.'/docs/guides/*.md') ?: [] as $guide) {
        $documentation .= "\n".file_get_contents($guide);
    }

    return $documentation;
}

/**
 * Dotted paths of every key in the schema: `runtime.requests.sampleRate`,
 * `ignore[].checkId`. Local `$ref`s are followed.
 *
 * @param  array<string, mixed>  $node
 * @param  array<string, mixed>  $schema
 * @return list<string>
 */
function schemaKeyPaths(array $node, array $schema, string $prefix = ''): array
{
    if (isset($node['$ref']) && is_string($node['$ref'])) {
        $resolved = $schema;

        foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
            $resolved = $resolved[$segment];
        }

        $node = [...$resolved, ...array_diff_key($node, ['$ref' => true])];
    }

    if (isset($node['items']) && is_array($node['items'])) {
        return schemaKeyPaths($node['items'], $schema, $prefix.'[]');
    }

    $paths = [];

    foreach ($node['properties'] ?? [] as $key => $child) {
        $path = $prefix === '' ? $key : $prefix.'.'.$key;
        $paths[] = $path;
        $paths = [...$paths, ...schemaKeyPaths($child, $schema, $path)];
    }

    return $paths;
}

/**
 * @param  list<string>  $names
 * @return list<string>
 */
function undocumented(array $names, string $documentation): array
{
    return array_values(array_filter($names, static fn (string $name): bool => ! str_contains($documentation, $name)));
}

it('documents every CLI and Artisan option', function (): void {
    $options = [];

    foreach ([new CheckCommand, new PushCommand, new ClearCacheCommand, new ExcubiaCheckCommand, new ExcubiaPushCommand, new ExcubiaPushRuntimeCommand, new ExcubiaHeartbeatCommand] as $command) {
        /** @var Command $command */
        foreach ($command->getDefinition()->getOptions() as $option) {
            $options[] = '--'.$option->getName();
        }
    }

    $options = array_values(array_unique($options));

    expect($options)->toContain('--format', '--strict', '--config')
        ->and(undocumented($options, userDocumentation()))->toBe([]);
});

it('documents every Artisan command and check id', function (): void {
    $names = array_map(static fn (Command $command): string => (string) $command->getName(), [
        new ExcubiaCheckCommand, new ExcubiaPushCommand, new ExcubiaPushRuntimeCommand, new ExcubiaHeartbeatCommand,
    ]);

    foreach (glob(dirname(__DIR__, 2).'/src/Checks/*Check.php') ?: [] as $file) {
        if (preg_match("/function id\\(\\): string\\s*\\{\\s*return '([a-z0-9-]+)';/", (string) file_get_contents($file), $match) === 1) {
            $names[] = '`'.$match[1].'`';
        }
    }

    expect($names)->toContain('excubia:check', '`php-eol`', '`laravel-maintenance-mode`')
        ->and(undocumented($names, userDocumentation()))->toBe([]);
});

it('documents every excubia.json key', function (): void {
    /** @var array<string, mixed> $schema */
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/excubia.schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $keys = array_map(static fn (string $path): string => '`'.$path.'`', schemaKeyPaths($schema, $schema));

    expect($keys)->toContain('`failOn`', '`ignore[].checkId`', '`runtime.requests.sampleRate`', '`runtime.slowQueries.thresholdMs`')
        ->and(undocumented($keys, userDocumentation()))->toBe([]);
});

it('documents every environment variable read in src', function (): void {
    $variables = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            preg_match_all("/['\"]((?:EXCUBIA_[A-Z0-9_]+)|GITHUB_TOKEN)['\"]/", (string) file_get_contents($file->getPathname()), $matches);
            $variables = [...$variables, ...$matches[1]];
        }
    }

    $variables = array_values(array_unique($variables));

    expect($variables)->toContain('EXCUBIA_APP_URL', 'EXCUBIA_APP_TOKEN', 'EXCUBIA_HEALTH_SECRET', 'GITHUB_TOKEN')
        ->and(undocumented(array_map(static fn (string $variable): string => '`'.$variable.'`', $variables), userDocumentation()))->toBe([]);
});
