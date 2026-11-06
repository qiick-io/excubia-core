<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Qiick\Excubia\Data\EnvironmentKeysData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\LockedPackageData;
use Qiick\Excubia\Data\PlatformRequirementData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Exceptions\LockfileNotFoundException;
use Qiick\Excubia\Parsers\ComposerJsonParser;
use Qiick\Excubia\Parsers\ComposerLockParser;
use Qiick\Excubia\Parsers\ConfigEnvironmentCallParser;
use Qiick\Excubia\Parsers\EnvironmentExampleParser;
use Qiick\Excubia\Parsers\NodeVersionParser;
use Qiick\Excubia\Parsers\PackageLockParser;
use Qiick\Excubia\Parsers\PnpmLockParser;
use Qiick\Excubia\Parsers\YarnLockParser;
use UnexpectedValueException;

/**
 * Reads a project directory into a snapshot. This is the only class that
 * touches manifest files.
 *
 * PHP version order is `config.platform.php`, then the lower bound of
 * `require.php`, then `platform.php` in the lockfile. Composer dev packages
 * are omitted when the configuration says so. npm packages are kept either
 * way, because `--no-dev` is specified for Composer only.
 *
 * One JavaScript lockfile is read (#21): the one of the `packageManager`
 * named in `package.json` when it exists, else the first present of
 * `pnpm-lock.yaml`, `yarn.lock` and `package-lock.json`.
 */
final class ProjectSnapshotService
{
    private const LARAVEL_FRAMEWORK_PACKAGE = 'laravel/framework';

    /**
     * JavaScript lockfile per package manager, in fallback order.
     */
    private const JAVASCRIPT_LOCKFILES = [
        'pnpm' => 'pnpm-lock.yaml',
        'yarn' => 'yarn.lock',
        'npm' => 'package-lock.json',
    ];

    public function __construct(
        private readonly ComposerJsonParser $composerJsonParser = new ComposerJsonParser,
        private readonly ComposerLockParser $composerLockParser = new ComposerLockParser,
        private readonly PackageLockParser $packageLockParser = new PackageLockParser,
        private readonly YarnLockParser $yarnLockParser = new YarnLockParser,
        private readonly PnpmLockParser $pnpmLockParser = new PnpmLockParser,
        private readonly NodeVersionParser $nodeVersionParser = new NodeVersionParser,
        private readonly VersionParser $versionParser = new VersionParser,
        private readonly EnvironmentExampleParser $environmentExampleParser = new EnvironmentExampleParser,
        private readonly ConfigEnvironmentCallParser $configEnvironmentCallParser = new ConfigEnvironmentCallParser,
    ) {}

    /**
     * @throws LockfileNotFoundException
     * @throws InvalidConfigurationException
     */
    public function snapshot(string $projectRoot, ExcubiaConfigurationData $configuration): ProjectSnapshotData
    {
        if (! is_dir($projectRoot)) {
            throw new InvalidConfigurationException('Project path ['.$projectRoot.'] is not a directory.');
        }

        $composerLockPath = $projectRoot.DIRECTORY_SEPARATOR.'composer.lock';

        if (! is_file($composerLockPath)) {
            throw new LockfileNotFoundException($projectRoot);
        }

        $composerJson = $this->optionalJsonObject($projectRoot.DIRECTORY_SEPARATOR.'composer.json');
        $parsedComposerJson = $composerJson === null
            ? [
                'platformPhpVersion' => null,
                'phpConstraint' => null,
                'directPackageNames' => [],
                'directDevelopmentPackageNames' => [],
            ]
            : $this->composerJsonParser->parse($composerJson);

        $directPackageNames = [
            ...$parsedComposerJson['directPackageNames'],
            ...$parsedComposerJson['directDevelopmentPackageNames'],
        ];

        $parsedLock = $this->composerLockParser->parse(
            $this->requiredJsonObject($composerLockPath),
            $directPackageNames,
        );

        $composerPackages = $this->filterDevelopmentPackages(
            $parsedLock['packages'],
            $configuration->isDevelopmentDependencyIncluded,
        );

        $packageJson = $this->optionalJsonObject($projectRoot.DIRECTORY_SEPARATOR.'package.json');
        $javascriptLockfile = $this->javascriptLockfile($projectRoot, $packageJson);
        $npmPackages = $javascriptLockfile === null
            ? []
            : $this->javascriptPackages($projectRoot, $javascriptLockfile, $this->npmDirectPackageNames($packageJson));

        return new ProjectSnapshotData(
            phpVersion: $this->resolvePhpVersion(
                $parsedComposerJson['platformPhpVersion'],
                $parsedComposerJson['phpConstraint'],
                $parsedLock['platformPhpVersion'],
            ),
            laravelFrameworkVersion: $this->laravelFrameworkVersion($composerPackages),
            composerPackages: $composerPackages,
            npmPackages: $npmPackages,
            nodeVersionDeclaration: $this->nodeVersionParser->declarationFrom(
                $this->optionalText($projectRoot.DIRECTORY_SEPARATOR.'.nvmrc'),
                $this->optionalText($projectRoot.DIRECTORY_SEPARATOR.'.node-version'),
                $this->enginesNode($packageJson),
            ),
            hasNpmLockfile: $javascriptLockfile !== null,
            requiredPackageNames: $parsedComposerJson['directPackageNames'],
            platformRequirements: array_values(array_filter(
                $parsedLock['platformRequirements'],
                static fn (PlatformRequirementData $requirement): bool => $configuration->isDevelopmentDependencyIncluded || ! $requirement->isDevelopmentDependency,
            )),
            providedPlatformNames: $parsedLock['providedPlatformNames'],
            platformOverrideNames: $parsedLock['platformOverrideNames'],
            javascriptLockfile: $javascriptLockfile,
            environmentKeys: $this->environmentKeys($projectRoot),
        );
    }

    /**
     * Key names of `.env.example` and the `env()` keys of `config/*.php`.
     * The path of `.env` is never built, so it cannot be opened by mistake.
     *
     * ponytail: only top-level `config/*.php`, as Laravel publishes them;
     * nested config directories are not scanned. Recurse when asked.
     *
     * @throws InvalidConfigurationException
     */
    private function environmentKeys(string $projectRoot): EnvironmentKeysData
    {
        $examplePath = $projectRoot.DIRECTORY_SEPARATOR.'.env.example';
        $configDirectory = $projectRoot.DIRECTORY_SEPARATOR.'config';
        $configKeys = null;

        if (is_dir($configDirectory)) {
            $configKeys = [];
            $configFiles = array_filter(glob($configDirectory.DIRECTORY_SEPARATOR.'*.php') ?: [], is_file(...));
            sort($configFiles, SORT_STRING);

            foreach ($configFiles as $configFile) {
                foreach ($this->configEnvironmentCallParser->keyNames($this->read($configFile)) as $key) {
                    $configKeys[$key][] = 'config/'.basename($configFile);
                }
            }

            ksort($configKeys, SORT_STRING);
        }

        return new EnvironmentKeysData(
            exampleKeys: is_file($examplePath) ? $this->environmentExampleParser->keyNames($this->read($examplePath)) : null,
            configKeys: $configKeys,
        );
    }

    /**
     * Name of the JavaScript lockfile to read, or null when there is none.
     * A `packageManager` (`pnpm@9.1.0`) whose lockfile is missing, or one
     * Excubia does not read, falls back to the fixed order.
     *
     * @param  array<string, mixed>|null  $packageJson
     */
    public function javascriptLockfile(string $projectRoot, ?array $packageJson): ?string
    {
        $packageManager = $packageJson['packageManager'] ?? null;
        $managerName = is_string($packageManager) ? strtolower(explode('@', $packageManager, 2)[0]) : '';
        $candidates = array_values(self::JAVASCRIPT_LOCKFILES);

        if (isset(self::JAVASCRIPT_LOCKFILES[$managerName])) {
            array_unshift($candidates, self::JAVASCRIPT_LOCKFILES[$managerName]);
        }

        foreach ($candidates as $fileName) {
            if (is_file($projectRoot.DIRECTORY_SEPARATOR.$fileName)) {
                return $fileName;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $directPackageNames
     * @return list<LockedPackageData>
     */
    private function javascriptPackages(string $projectRoot, string $javascriptLockfile, array $directPackageNames): array
    {
        $path = $projectRoot.DIRECTORY_SEPARATOR.$javascriptLockfile;

        return match ($javascriptLockfile) {
            'pnpm-lock.yaml' => $this->pnpmLockParser->parse(
                $this->read($path),
                $this->optionalText($projectRoot.DIRECTORY_SEPARATOR.'.npmrc'),
                $directPackageNames,
            ),
            'yarn.lock' => $this->yarnLockParser->parse(
                $this->read($path),
                $this->optionalText($projectRoot.DIRECTORY_SEPARATOR.'.yarnrc.yml'),
                $directPackageNames,
            ),
            default => $this->packageLockParser->parse($this->requiredJsonObject($path), $directPackageNames),
        };
    }

    /**
     * Project-relative name of the file the Node declaration comes from, in
     * the same order as `snapshot`: `.nvmrc`, `.node-version`, then
     * `package.json`. The SARIF renderer uses it as the `node-eol` location.
     */
    public function nodeVersionManifest(string $projectRoot): string
    {
        foreach (['.nvmrc', '.node-version'] as $fileName) {
            $contents = $this->optionalText($projectRoot.DIRECTORY_SEPARATOR.$fileName);

            if ($this->nodeVersionParser->declarationFrom($contents, null, null) !== null) {
                return $fileName;
            }
        }

        return 'package.json';
    }

    /**
     * @param  list<LockedPackageData>  $packages
     * @return list<LockedPackageData>
     */
    private function filterDevelopmentPackages(array $packages, bool $isDevelopmentDependencyIncluded): array
    {
        if ($isDevelopmentDependencyIncluded) {
            return $packages;
        }

        $filtered = [];

        foreach ($packages as $package) {
            if ($package->isDevelopmentDependency) {
                continue;
            }

            $filtered[] = $package;
        }

        return $filtered;
    }

    /**
     * @param  list<LockedPackageData>  $packages
     */
    private function laravelFrameworkVersion(array $packages): ?string
    {
        foreach ($packages as $package) {
            if ($package->name === self::LARAVEL_FRAMEWORK_PACKAGE && ! $package->isDevelopmentDependency) {
                return $package->version;
            }
        }

        foreach ($packages as $package) {
            if ($package->name === self::LARAVEL_FRAMEWORK_PACKAGE) {
                return $package->version;
            }
        }

        return null;
    }

    private function resolvePhpVersion(?string $platformPhpVersion, ?string $phpConstraint, ?string $lockPlatformPhpVersion): ?string
    {
        foreach ([$platformPhpVersion, $phpConstraint, $lockPlatformPhpVersion] as $index => $candidate) {
            if ($candidate === null) {
                continue;
            }

            $normalized = $this->normalizeExactVersion($candidate);

            if ($normalized !== null) {
                return $normalized;
            }

            if ($index !== 1) {
                continue;
            }

            $lowerBound = $this->lowerBound($candidate);

            if ($lowerBound !== null) {
                return $lowerBound;
            }
        }

        return null;
    }

    /**
     * Exact versions normalize. Constraints such as `^8.2` do not, and the
     * caller falls through to the lower bound for `require.php` only.
     */
    private function normalizeExactVersion(string $version): ?string
    {
        try {
            return $this->versionParser->normalize(ltrim($version, 'vV'));
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Lowest concrete version a Composer constraint still allows.
     *
     * `*` and other constraints that start at `0.0.0.0` have no useful
     * bound, so the next PHP source is tried. An `||` constraint uses the
     * lowest start. Composer records that start as `x.y.z.0-dev`; the
     * stable version is what end-of-life checks compare.
     */
    private function lowerBound(string $constraintText): ?string
    {
        try {
            $constraint = $this->versionParser->parseConstraints($constraintText);
        } catch (UnexpectedValueException) {
            return null;
        }

        $intervals = Intervals::get($constraint);
        $lowest = null;

        foreach ($intervals['numeric'] as $interval) {
            $version = $interval->getStart()->getVersion();

            if (str_ends_with($version, '-dev')) {
                $version = substr($version, 0, -strlen('-dev'));
            }

            if ($version === '0.0.0.0' || $version === '') {
                continue;
            }

            if ($lowest === null || version_compare($version, $lowest, '<')) {
                $lowest = $version;
            }
        }

        return $lowest;
    }

    /**
     * @param  array<string, mixed>|null  $packageJson
     * @return list<string>
     */
    private function npmDirectPackageNames(?array $packageJson): array
    {
        if ($packageJson === null) {
            return [];
        }

        $names = [];

        foreach (['dependencies', 'devDependencies', 'optionalDependencies'] as $key) {
            $dependencies = $packageJson[$key] ?? null;

            if ($dependencies === null) {
                continue;
            }

            if (! is_array($dependencies)) {
                throw new InvalidConfigurationException('package.json '.$key.' must be an object.');
            }

            foreach ($dependencies as $name => $constraint) {
                if (! is_string($name) || ! is_string($constraint)) {
                    throw new InvalidConfigurationException('package.json '.$key.' entries must be package constraints.');
                }

                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>|null  $packageJson
     */
    private function enginesNode(?array $packageJson): ?string
    {
        if ($packageJson === null) {
            return null;
        }

        $engines = $packageJson['engines'] ?? null;

        if ($engines === null) {
            return null;
        }

        if (! is_array($engines)) {
            throw new InvalidConfigurationException('package.json engines must be an object.');
        }

        $node = $engines['node'] ?? null;

        if ($node === null) {
            return null;
        }

        if (! is_string($node) || $node === '') {
            throw new InvalidConfigurationException('package.json engines.node must be a string.');
        }

        return $node;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function optionalJsonObject(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        return $this->requiredJsonObject($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function requiredJsonObject(string $path): array
    {
        $contents = $this->read($path);

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $reason = str_ends_with($path, 'composer.lock')
                ? $path.' is broken or unparseable.'
                : $path.' is not valid JSON.';

            throw new InvalidConfigurationException($reason, previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new InvalidConfigurationException($path.' must be a JSON object.');
        }

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidConfigurationException($path.' must be a JSON object.');
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function optionalText(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        return $this->read($path);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidConfigurationException($path.' could not be read.');
        }

        return $contents;
    }
}
