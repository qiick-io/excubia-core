<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Closure;
use Illuminate\Console\Command;
use Qiick\Excubia\Console\CheckCommand;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * `php artisan excubia:check`. Same engine as `vendor/bin/excubia check`,
 * with the path fixed to the application base path. With the health
 * endpoint enabled, the result is stored for it (`HealthEndpointService`).
 * with the path fixed to the application base path and the posture of the
 * booted app (`LaravelPostureService`) attached for the `laravel-*` checks.
 */
final class ExcubiaCheckCommand extends Command
{
    protected $signature = 'excubia:check
        {--format= : table, json, markdown, or sarif}
        {--output= : Write the report to this file}
        {--fail-on= : critical, high, medium, low, or none}
        {--only= : Comma-separated check ids to run}
        {--skip= : Comma-separated check ids to skip}
        {--no-dev : Ignore Composer packages-dev}
        {--offline : Use the cache only}
        {--no-cache : Bypass the cache}
        {--config= : Config file}
        {--no-banner : Hide the ASCII banner}
        {--strict : Exit 3 when a data source failure skipped a check}';

    protected $description = 'Health checks for Laravel apps.';

    public function handle(): int
    {
        $configPath = $this->stringOption('config');
        $laravelPosture = (new LaravelPostureService)->collect($this->getLaravel());

        return HealthEndpointService::recording($this->laravel, $configPath, fn (?Closure $reportListener): int => (new CheckCommand(reportListener: $reportListener, laravelPosture: $laravelPosture))->runWithOptions(self::applicationBasePath($this->getLaravel()), [
            'format' => $this->stringOption('format'),
            'output' => $this->stringOption('output'),
            'fail-on' => $this->stringOption('fail-on'),
            'only' => $this->stringOption('only'),
            'skip' => $this->stringOption('skip'),
            'no-dev' => (bool) $this->option('no-dev'),
            'offline' => (bool) $this->option('offline'),
            'no-cache' => (bool) $this->option('no-cache'),
            'config' => $configPath,
            'no-banner' => (bool) $this->option('no-banner'),
            'strict' => (bool) $this->option('strict'),
        ], $this->output), $this->output);
    }

    /**
     * The scanned path for every Artisan alias.
     *
     * @throws InvalidConfigurationException
     */
    public static function applicationBasePath(mixed $laravel): string
    {
        if (! is_object($laravel) || ! method_exists($laravel, 'basePath')) {
            throw new InvalidConfigurationException('The Artisan alias needs the Laravel application base path.');
        }

        $basePath = $laravel->basePath();

        if (! is_string($basePath) || $basePath === '') {
            throw new InvalidConfigurationException('The Laravel application base path is not a directory.');
        }

        return $basePath;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidConfigurationException('--'.$name.' must be a string.');
        }

        return $value;
    }
}
