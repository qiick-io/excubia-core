<?php

declare(strict_types=1);

use Composer\Semver\Semver;

/**
 * @return array<string, mixed>
 */
function decodedJsonFile(string $relativePath): array
{
    $document = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/'.$relativePath), true, flags: JSON_THROW_ON_ERROR);

    return is_array($document) ? $document : [];
}

/**
 * @return array<string, array<string, mixed>>
 */
function actionLockedPackages(): array
{
    $packages = [];

    foreach (decodedJsonFile('.github/action-install/composer.lock')['packages'] as $package) {
        $packages[$package['name']] = $package;
    }

    return $packages;
}

it('installs the GitHub Action from the committed lock without plugins or scripts', function () {
    $action = (string) file_get_contents(dirname(__DIR__, 2).'/action.yml');

    expect($action)->toContain('.github/action-install/composer.lock')
        ->and($action)->toContain('composer install --no-interaction --no-progress --prefer-dist --no-dev --no-plugins --no-scripts')
        ->and($action)->not->toContain('cat > composer.json');
});

it('keeps the action lock in sync with composer.json', function () {
    $root = decodedJsonFile('composer.json');
    $locked = actionLockedPackages();
    $excubia = $locked['qiick/excubia-core'] ?? [];

    expect($excubia['require'] ?? null)->toEqual($root['require'])
        ->and($excubia['autoload'] ?? null)->toBe($root['autoload'])
        ->and($excubia['bin'] ?? null)->toBe($root['bin']);

    foreach ($root['require'] as $name => $constraint) {
        if ($name === 'php') {
            continue;
        }

        expect($locked)->toHaveKey($name)
            ->and(Semver::satisfies(ltrim($locked[$name]['version'], 'v'), $constraint))->toBeTrue("{$name} {$locked[$name]['version']} does not satisfy {$constraint}");
    }
});

it('locks only runtime dependencies that install on the PHP 8.2 floor', function () {
    $lock = decodedJsonFile('.github/action-install/composer.lock');

    expect($lock['packages-dev'])->toBe([])
        ->and($lock['platform-overrides'] ?? null)->toBe(['php' => '8.2.0']);

    foreach (actionLockedPackages() as $name => $package) {
        $constraint = $package['require']['php'] ?? '*';

        expect(Semver::satisfies('8.2.0', $constraint))->toBeTrue("{$name} requires PHP {$constraint}");
    }
});
