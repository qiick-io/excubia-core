<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

/**
 * Resolves the release identifier that ties a report, a runtime batch or an
 * exception batch to a deploy (`docs/SPEC.md` section 13).
 *
 * Order: the `release` key of `excubia.json`, `EXCUBIA_RELEASE`, the CI and
 * platform variables in `PLATFORM_VARIABLES` (in that order), then, only
 * when the caller passes the project root, the commit checked out there. The first source that is set wins; when its
 * value is longer than 100 characters or uses a character outside
 * `[A-Za-z0-9._+/@-]`, the release is null (later sources are not tried).
 *
 * The git fallback does not run `git`: the tool must not execute anything
 * in the scanned project. It reads `.git/HEAD` and the one ref file it
 * names, or `packed-refs`, and keeps only the commit id. A `.git` file
 * (worktree, submodule) is followed through its `gitdir:` and `commondir`
 * pointers. No branch name, author or message is reported.
 */
final class ReleaseService
{
    public const MAX_LENGTH = 100;

    public const PLATFORM_VARIABLES = ['GITHUB_SHA', 'CI_COMMIT_SHA', 'VERCEL_GIT_COMMIT_SHA', 'FORGE_DEPLOY_COMMIT', 'LARAVEL_CLOUD_COMMIT'];

    private const SYNTAX = '#^[A-Za-z0-9._+/@-]{1,100}$#';

    private const OBJECT_ID = '#^(?:[0-9a-f]{40}|[0-9a-f]{64})$#';

    /**
     * @param  array<string, string>|null  $environment  tests pass variables here; null reads getenv()
     */
    public function __construct(private readonly ?array $environment = null) {}

    /**
     * @param  string|null  $configuredRelease  the `release` key of `excubia.json`
     * @param  string|null  $gitProjectRoot  the directory whose `.git` the fallback reads; null disables it
     */
    public function resolve(?string $configuredRelease, ?string $gitProjectRoot = null): ?string
    {
        $candidate = $configuredRelease !== null && $configuredRelease !== '' ? $configuredRelease : null;

        foreach (['EXCUBIA_RELEASE', ...self::PLATFORM_VARIABLES] as $name) {
            if ($candidate !== null) {
                break;
            }

            $candidate = $this->environmentValue($name);
        }

        if ($candidate === null && $gitProjectRoot !== null) {
            $candidate = $this->gitCommit($gitProjectRoot);
        }

        return $candidate !== null && self::isValid($candidate) ? $candidate : null;
    }

    public static function isValid(string $release): bool
    {
        return preg_match(self::SYNTAX, $release) === 1;
    }

    private function environmentValue(string $name): ?string
    {
        $value = $this->environment !== null ? ($this->environment[$name] ?? null) : getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The commit id checked out in `$projectRoot`, or null when it has no
     * `.git` or it cannot be read.
     *
     * ponytail: only the project root is looked at, not its parents, so a
     * project in a subdirectory of a monorepo gets no git fallback; walk up
     * to the filesystem root if that case shows up (set EXCUBIA_RELEASE meanwhile).
     */
    private function gitCommit(string $projectRoot): ?string
    {
        $gitPath = rtrim($projectRoot, '/\\').DIRECTORY_SEPARATOR.'.git';

        if (is_dir($gitPath)) {
            return $this->commitIn($gitPath);
        }

        $gitDirectory = is_file($gitPath) ? $this->pointedDirectory($gitPath, 'gitdir: ') : null;

        return $gitDirectory === null ? null : $this->commitIn($gitDirectory);
    }

    /**
     * Follow `HEAD` to a commit id: a detached HEAD holds it directly, a
     * symbolic one names a ref looked up in the git directory, its common
     * directory (worktrees) and then `packed-refs`.
     */
    private function commitIn(string $gitDirectory): ?string
    {
        $head = $this->firstLine($gitDirectory.DIRECTORY_SEPARATOR.'HEAD');

        if ($head === null) {
            return null;
        }

        if (! str_starts_with($head, 'ref: ')) {
            return preg_match(self::OBJECT_ID, $head) === 1 ? $head : null;
        }

        $reference = substr($head, 5);

        if (preg_match('#^refs/[A-Za-z0-9._/+@-]+$#', $reference) !== 1 || str_contains($reference, '..')) {
            return null;
        }

        $commonDirectory = is_file($gitDirectory.DIRECTORY_SEPARATOR.'commondir')
            ? $this->pointedDirectory($gitDirectory.DIRECTORY_SEPARATOR.'commondir', '')
            : $gitDirectory;

        foreach (array_unique([$gitDirectory, $commonDirectory ?? $gitDirectory]) as $directory) {
            $commit = $this->firstLine($directory.DIRECTORY_SEPARATOR.$reference);

            if ($commit !== null && preg_match(self::OBJECT_ID, $commit) === 1) {
                return $commit;
            }
        }

        return $this->packedCommit($commonDirectory ?? $gitDirectory, $reference);
    }

    private function packedCommit(string $gitDirectory, string $reference): ?string
    {
        $path = $gitDirectory.DIRECTORY_SEPARATOR.'packed-refs';
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            return null;
        }

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $parts = explode(' ', $line, 2);

            if (count($parts) === 2 && $parts[1] === $reference && preg_match(self::OBJECT_ID, $parts[0]) === 1) {
                return $parts[0];
            }
        }

        return null;
    }

    /**
     * The directory a pointer file names (`gitdir: <path>` in a `.git` file,
     * a bare path in `commondir`), resolved against the file's directory
     * when relative.
     */
    private function pointedDirectory(string $pointerFile, string $prefix): ?string
    {
        $line = $this->firstLine($pointerFile);

        if ($line === null || ! str_starts_with($line, $prefix) || strlen($line) === strlen($prefix)) {
            return null;
        }

        $target = substr($line, strlen($prefix));
        $isAbsolute = str_starts_with($target, '/') || str_starts_with($target, '\\') || preg_match('#^[A-Za-z]:[\\\\/]#', $target) === 1;

        return $isAbsolute ? $target : dirname($pointerFile).DIRECTORY_SEPARATOR.$target;
    }

    private function firstLine(string $path): ?string
    {
        $contents = is_file($path) ? file_get_contents($path, length: 4096) : false;

        if ($contents === false) {
            return null;
        }

        $line = trim(strtok($contents, "\r\n") ?: '');

        return $line === '' ? null : $line;
    }
}
