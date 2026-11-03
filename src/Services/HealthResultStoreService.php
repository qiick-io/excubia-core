<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * The last Oh Dear health payload, kept as one small JSON file so the
 * health endpoint can answer without running a check in the request.
 *
 * The Laravel aliases write it after each run; the endpoint only reads it.
 * The file holds check ids, statuses and package names, never a secret.
 */
final class HealthResultStoreService
{
    public function __construct(private readonly string $path) {}

    /**
     * Replace the stored payload. Written to a temporary file and renamed,
     * so a request never reads half a file.
     *
     * @throws InvalidConfigurationException
     */
    public function write(string $payload): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new InvalidConfigurationException('The health result directory could not be created.');
        }

        $temporaryPath = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($temporaryPath, $payload) === false || ! rename($temporaryPath, $this->path)) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

            throw new InvalidConfigurationException('The health result could not be stored.');
        }
    }

    /**
     * The stored payload, or null when there is none or it is not a valid
     * Oh Dear document (`finishedAt` integer and a `checkResults` list).
     */
    public function read(): ?string
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ! is_int($decoded['finishedAt'] ?? null) || ! is_array($decoded['checkResults'] ?? null) || ! array_is_list($decoded['checkResults'])) {
            return null;
        }

        return $contents;
    }
}
