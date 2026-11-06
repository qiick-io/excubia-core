<?php

declare(strict_types=1);

use Qiick\Excubia\Services\ReleaseService;

const RELEASE_COMMIT = '0123456789abcdef0123456789abcdef01234567';
const RELEASE_OTHER_COMMIT = 'fedcba9876543210fedcba9876543210fedcba98';

it('resolves the release in order: config, EXCUBIA_RELEASE, platform variables, git', function () {
    $project = temporaryProject(['.git/HEAD' => RELEASE_COMMIT."\n"]);
    $everything = [
        'EXCUBIA_RELEASE' => 'v1.2.3',
        'GITHUB_SHA' => 'github',
        'CI_COMMIT_SHA' => 'gitlab',
        'VERCEL_GIT_COMMIT_SHA' => 'vercel',
        'FORGE_DEPLOY_COMMIT' => 'forge',
        'LARAVEL_CLOUD_COMMIT' => 'cloud',
    ];

    expect((new ReleaseService($everything))->resolve('from-config', $project))->toBe('from-config')
        ->and((new ReleaseService($everything))->resolve(null, $project))->toBe('v1.2.3')
        ->and((new ReleaseService(array_diff_key($everything, ['EXCUBIA_RELEASE' => true])))->resolve(null, $project))->toBe('github')
        ->and((new ReleaseService(['CI_COMMIT_SHA' => 'gitlab', 'VERCEL_GIT_COMMIT_SHA' => 'vercel']))->resolve(null, $project))->toBe('gitlab')
        ->and((new ReleaseService(['VERCEL_GIT_COMMIT_SHA' => 'vercel', 'FORGE_DEPLOY_COMMIT' => 'forge']))->resolve(null, $project))->toBe('vercel')
        ->and((new ReleaseService(['FORGE_DEPLOY_COMMIT' => 'forge', 'LARAVEL_CLOUD_COMMIT' => 'cloud']))->resolve(null, $project))->toBe('forge')
        ->and((new ReleaseService(['LARAVEL_CLOUD_COMMIT' => 'cloud']))->resolve(null, $project))->toBe('cloud')
        ->and((new ReleaseService([]))->resolve(null, $project))->toBe(RELEASE_COMMIT)
        ->and((new ReleaseService([]))->resolve(null))->toBeNull()
        ->and((new ReleaseService([]))->resolve(null, temporaryProject([])))->toBeNull();
});

it('treats empty values as unset', function () {
    expect((new ReleaseService(['EXCUBIA_RELEASE' => '', 'GITHUB_SHA' => 'abc123']))->resolve(''))->toBe('abc123');
});

it('reads the process environment when no environment is injected', function () {
    putenv('EXCUBIA_RELEASE=from-getenv');

    try {
        expect((new ReleaseService)->resolve(null))->toBe('from-getenv');
    } finally {
        putenv('EXCUBIA_RELEASE');
    }
});

it('accepts up to 100 characters of the allowed set and reports anything else as null', function (string $release, bool $isValid) {
    expect(ReleaseService::isValid($release))->toBe($isValid)
        ->and((new ReleaseService(['EXCUBIA_RELEASE' => $release]))->resolve(null))->toBe($isValid ? $release : null);
})->with([
    'semver with build' => ['v1.2.3+build.7', true],
    'path-like tag' => ['release/2026-11-06@web_1', true],
    'commit id' => [RELEASE_COMMIT, true],
    'exactly 100 characters' => [str_repeat('a', 100), true],
    '101 characters' => [str_repeat('a', 101), false],
    'space' => ['v1 2', false],
    'shell characters' => ['v1;rm -rf', false],
    'newline' => ["v1\nv2", false],
    'non-ASCII' => ['vé1', false],
]);

it('does not fall through to the next source when the first one set is invalid', function () {
    $project = temporaryProject(['.git/HEAD' => RELEASE_COMMIT."\n"]);

    expect((new ReleaseService(['EXCUBIA_RELEASE' => 'ok']))->resolve('not valid', $project))->toBeNull()
        ->and((new ReleaseService(['EXCUBIA_RELEASE' => 'not valid', 'GITHUB_SHA' => 'ok']))->resolve(null, $project))->toBeNull();
});

it('follows a symbolic HEAD to a loose ref and returns only the commit id', function () {
    $project = temporaryProject([
        '.git/HEAD' => "ref: refs/heads/feature/secret-branch-name\n",
        '.git/refs/heads/feature/secret-branch-name' => RELEASE_COMMIT."\n",
    ]);

    expect((new ReleaseService([]))->resolve(null, $project))->toBe(RELEASE_COMMIT);
});

it('falls back to packed-refs when the ref has no loose file', function () {
    $project = temporaryProject([
        '.git/HEAD' => "ref: refs/heads/main\n",
        '.git/packed-refs' => "# pack-refs with: peeled fully-peeled sorted\n".RELEASE_OTHER_COMMIT." refs/heads/other\n".RELEASE_COMMIT." refs/heads/main\n^".RELEASE_OTHER_COMMIT."\n",
    ]);

    expect((new ReleaseService([]))->resolve(null, $project))->toBe(RELEASE_COMMIT);
});

it('follows the gitdir and commondir pointers of a worktree', function () {
    $repository = temporaryProject([
        '.git/packed-refs' => RELEASE_COMMIT." refs/heads/packed\n",
        '.git/refs/heads/loose' => RELEASE_OTHER_COMMIT."\n",
        '.git/worktrees/one/HEAD' => "ref: refs/heads/loose\n",
        '.git/worktrees/one/commondir' => "../..\n",
        '.git/worktrees/two/HEAD' => "ref: refs/heads/packed\n",
        '.git/worktrees/two/commondir' => "../..\n",
    ]);
    $absolute = temporaryProject(['.git' => 'gitdir: '.$repository."/.git/worktrees/one\n"]);
    $relative = temporaryProject([]);
    file_put_contents($relative.'/.git', 'gitdir: '.str_repeat('../', substr_count($relative, '/')).ltrim($repository, '/')."/.git/worktrees/two\n");

    expect((new ReleaseService([]))->resolve(null, $absolute))->toBe(RELEASE_OTHER_COMMIT)
        ->and((new ReleaseService([]))->resolve(null, $relative))->toBe(RELEASE_COMMIT);
});

it('returns null for git data it cannot trust', function (array $files) {
    expect((new ReleaseService([]))->resolve(null, temporaryProject($files)))->toBeNull();
})->with([
    'empty HEAD' => [['.git/HEAD' => "\n"]],
    'HEAD that is not a commit id' => [['.git/HEAD' => "not-a-commit\n"]],
    'missing ref' => [['.git/HEAD' => "ref: refs/heads/main\n"]],
    'ref outside refs/' => [['.git/HEAD' => "ref: config\n", '.git/config' => RELEASE_COMMIT]],
    'ref with ..' => [['.git/HEAD' => "ref: refs/../../outside\n"]],
    'ref file that is not a commit id' => [['.git/HEAD' => "ref: refs/heads/main\n", '.git/refs/heads/main' => "oops\n"]],
    '.git file without gitdir' => [['.git' => "something else\n"]],
    '.git file with an empty gitdir' => [['.git' => "gitdir: \n"]],
]);

it('reads only the project directory, not its parents', function () {
    $parent = temporaryProject(['.git/HEAD' => RELEASE_COMMIT."\n", 'app/composer.json' => '{}']);

    expect((new ReleaseService([]))->resolve(null, $parent.'/app'))->toBeNull()
        ->and((new ReleaseService([]))->resolve(null, $parent.'/'))->toBe(RELEASE_COMMIT);
});
