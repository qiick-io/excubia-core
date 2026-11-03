<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use DateTimeImmutable;
use DateTimeZone;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * File cache for data-source bodies.
 *
 * A null directory uses a per-user directory in the operating-system
 * temporary directory so the scanned project is not written. That shared
 * location is trusted only when it is a real directory owned by the current
 * user and not writable by others; otherwise the cache is skipped, so another
 * local user cannot plant responses (hiding advisories) or point it at their
 * choice of directory with a symlink. The cache key is a hash of the request
 * identity. Headers, including a GitHub token, are not part of the key and
 * are not written. Only files named like a cache key are ever deleted.
 */
final class ResponseCacheService
{
    /**
     * @param  string|null  $temporaryDirectory  tests point the shared temporary directory elsewhere; null is sys_get_temp_dir()
     */
    public function __construct(
        private readonly ?string $directory,
        private readonly int $ttlHours,
        private readonly bool $isOffline,
        private readonly bool $isBypassed,
        private readonly ?string $temporaryDirectory = null,
    ) {}

    /**
     * @param  callable(): array<int|string, mixed>  $resolve
     * @return array<int|string, mixed>
     *
     * @throws DataSourceUnavailableException
     * @throws InvalidConfigurationException
     */
    public function remember(string $cacheKey, callable $resolve): array
    {
        $isUsable = ! $this->isBypassed && $this->isTrustedDirectory();

        if ($isUsable) {
            $cached = $this->read($cacheKey);

            if ($cached !== null) {
                return $cached;
            }
        }

        if ($this->isOffline) {
            throw new DataSourceUnavailableException($cacheKey, 'No cached response is available for offline mode.');
        }

        $body = $resolve();

        if ($isUsable) {
            $this->write($cacheKey, $body);
        }

        return $body;
    }

    /**
     * Delete cached response files. A missing directory is already clear.
     *
     * Only the cache directory is touched. The scanned project is not.
     *
     * @throws InvalidConfigurationException
     */
    public function clear(): void
    {
        $directory = $this->directory();

        if (! is_dir($directory) || ! $this->isTrustedDirectory()) {
            return;
        }

        $files = glob($directory.DIRECTORY_SEPARATOR.'*.json');

        if ($files === false) {
            throw new InvalidConfigurationException('The response cache could not be listed.');
        }

        foreach ($files as $file) {
            if (! is_file($file) || preg_match('/^[0-9a-f]{64}\.json$/', basename($file)) !== 1) {
                continue;
            }

            if (! unlink($file)) {
                throw new InvalidConfigurationException('The response cache could not be cleared.');
            }
        }
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function read(string $cacheKey): ?array
    {
        $path = $this->path($cacheKey);

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! is_string($decoded['storedAt'] ?? null) || ! is_array($decoded['body'] ?? null)) {
            return null;
        }

        $storedAt = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $decoded['storedAt'], new DateTimeZone('UTC'));

        if ($storedAt === false) {
            return null;
        }

        $ageInSeconds = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp() - $storedAt->getTimestamp();

        if ($ageInSeconds > $this->ttlHours * 3600) {
            return null;
        }

        return $decoded['body'];
    }

    /**
     * @param  array<int|string, mixed>  $body
     */
    private function write(string $cacheKey, array $body): void
    {
        $directory = $this->directory();

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new InvalidConfigurationException('The response cache directory ['.$directory.'] could not be created.');
        }

        // Checked again after mkdir: another user may have created it in between.
        if (! $this->isTrustedDirectory()) {
            return;
        }

        $payload = json_encode([
            'storedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM),
            'body' => $body,
        ], JSON_THROW_ON_ERROR);

        if (file_put_contents($this->path($cacheKey), $payload) === false) {
            throw new InvalidConfigurationException('The response cache could not be written.');
        }
    }

    private function path(string $cacheKey): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.hash('sha256', $cacheKey).'.json';
    }

    private function directory(): string
    {
        if ($this->isConfiguredDirectory()) {
            return (string) $this->directory;
        }

        $user = function_exists('posix_geteuid') ? (string) posix_geteuid() : get_current_user();

        return ($this->temporaryDirectory ?? sys_get_temp_dir()).DIRECTORY_SEPARATOR.'qiick-excubia-'.(preg_replace('/[^A-Za-z0-9_.-]/', '_', $user) ?? 'user');
    }

    private function isConfiguredDirectory(): bool
    {
        return $this->directory !== null && $this->directory !== '';
    }

    /**
     * A configured directory is the user's choice. The default one, in the
     * shared temporary directory, must not exist yet or be a real directory
     * owned by this user that nobody else can write to.
     */
    private function isTrustedDirectory(): bool
    {
        if ($this->isConfiguredDirectory()) {
            return true;
        }

        $directory = $this->directory();
        clearstatcache(true, $directory);

        if (is_link($directory)) {
            return false;
        }

        if (! file_exists($directory)) {
            return true;
        }

        $permissions = fileperms($directory);
        $isOwned = ! function_exists('posix_geteuid') || fileowner($directory) === posix_geteuid();

        return is_dir($directory) && $isOwned && $permissions !== false && ($permissions & 0o022) === 0;
    }
}
