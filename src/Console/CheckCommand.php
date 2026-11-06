<?php

declare(strict_types=1);

namespace Qiick\Excubia\Console;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Qiick\Excubia\Configuration\ExcubiaConfigurationLoader;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Contracts\ReportRendererContract;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\HealthReportData;
use Qiick\Excubia\Data\LaravelPostureData;
use Qiick\Excubia\Enums\ExitCodeEnum;
use Qiick\Excubia\Enums\ReportFormatEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Exceptions\LockfileNotFoundException;
use Qiick\Excubia\Renderers\JsonReportRenderer;
use Qiick\Excubia\Renderers\MarkdownReportRenderer;
use Qiick\Excubia\Renderers\SarifRenderer;
use Qiick\Excubia\Renderers\TableReportRenderer;
use Qiick\Excubia\Services\HealthCheckRunnerService;
use Qiick\Excubia\Services\NotifyingHttpClientService;
use Qiick\Excubia\Services\ProjectSnapshotService;
use Qiick\Excubia\Services\ReleaseService;
use Qiick\Excubia\Services\SymfonyHttpClientService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `excubia check`. The Artisan alias calls `runWithOptions` so both entry
 * points share this command. The command does not boot the scanned project.
 * The Artisan alias passes the posture it read from the booted app; the
 * standalone CLI passes none, so the posture checks are skipped.
 * The report carries the release from `ReleaseService`; the git fallback of
 * that service runs only for the standalone CLI (no posture passed).
 */
final class CheckCommand extends Command
{
    /**
     * `excubia.json` is normally committed, so a token there ends up in Git
     * history and every clone. Removing `app.token` would break existing
     * configs, so it keeps working with a warning.
     */
    public const APP_TOKEN_IN_FILE_WARNING = 'app.token is set in excubia.json; prefer EXCUBIA_APP_TOKEN so the token is not committed.';

    /**
     * @param  (Closure(HealthReportData): void)|null  $reportListener  called with every finished report;
     *                                                                  the Laravel aliases store it for the health endpoint
     * @param  array<string, string>|null  $environment  tests pass variables here; null reads getenv()
     */
    public function __construct(
        private readonly ?HttpClientContract $httpClient = null,
        private readonly ?DateTimeImmutable $today = null,
        private readonly ?Closure $reportListener = null,
        private readonly ?LaravelPostureData $laravelPosture = null,
        private readonly ?array $environment = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('check')
            ->setDescription('Health checks for Laravel apps.')
            ->addArgument('path', InputArgument::OPTIONAL, 'Project directory')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table, json, markdown, or sarif')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write the report to this file')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'critical, high, medium, low, or none')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated check ids to run')
            ->addOption('skip', null, InputOption::VALUE_REQUIRED, 'Comma-separated check ids to skip')
            ->addOption('no-dev', null, InputOption::VALUE_NONE, 'Ignore Composer packages-dev')
            ->addOption('offline', null, InputOption::VALUE_NONE, 'Use the cache only')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Bypass the cache')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Config file')
            ->addOption('no-banner', null, InputOption::VALUE_NONE, 'Hide the ASCII banner')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit 3 when a data source failure skipped a check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $path = $input->getArgument('path');

            return $this->runWithOptions(is_string($path) ? $path : null, [
                'format' => $this->stringOption($input, 'format'),
                'output' => $this->stringOption($input, 'output'),
                'fail-on' => $this->stringOption($input, 'fail-on'),
                'only' => $this->stringOption($input, 'only'),
                'skip' => $this->stringOption($input, 'skip'),
                'no-dev' => (bool) $input->getOption('no-dev'),
                'offline' => (bool) $input->getOption('offline'),
                'no-cache' => (bool) $input->getOption('no-cache'),
                'config' => $this->stringOption($input, 'config'),
                'no-banner' => (bool) $input->getOption('no-banner'),
                'strict' => (bool) $input->getOption('strict'),
            ], $output);
        } catch (InvalidConfigurationException|LockfileNotFoundException $exception) {
            self::writeError($output, $exception->getMessage());

            return $exception->getCode();
        }
    }

    /**
     * Same engine as `execute`. Artisan passes the application base path
     * and does not take a path argument.
     *
     * @param  array{
     *     format?: string|null,
     *     output?: string|null,
     *     fail-on?: string|null,
     *     only?: string|null,
     *     skip?: string|null,
     *     no-dev?: bool,
     *     offline?: bool,
     *     no-cache?: bool,
     *     config?: string|null,
     *     no-banner?: bool,
     *     strict?: bool
     * }  $options
     */
    public function runWithOptions(?string $path, array $options, OutputInterface $output): int
    {
        try {
            return $this->render($path, $options, $output);
        } catch (InvalidConfigurationException|LockfileNotFoundException $exception) {
            self::writeError($output, $exception->getMessage());

            return $exception->getCode();
        }
    }

    /**
     * Run the checks, render the report, return the exit code.
     *
     * @param  array{
     *     format?: string|null,
     *     output?: string|null,
     *     fail-on?: string|null,
     *     only?: string|null,
     *     skip?: string|null,
     *     no-dev?: bool,
     *     offline?: bool,
     *     no-cache?: bool,
     *     config?: string|null,
     *     no-banner?: bool,
     *     strict?: bool
     * }  $options
     *
     * @throws InvalidConfigurationException
     * @throws LockfileNotFoundException
     */
    private function render(?string $path, array $options, OutputInterface $output): int
    {
        $scan = $this->scan($path, $options, $output);
        $rendered = $this->renderer($options['format'] ?? null, $output, $options['output'] ?? null, $path)->render($scan['report']);
        $this->writeReport($rendered, $options['output'] ?? null, $options['no-banner'] ?? false, $output);
        $exitCode = $scan['report']->exitCodeFor($scan['configuration']->failOnSeverity, $scan['configuration']->isStrict);

        if ($exitCode === ExitCodeEnum::DataSourceFailure) {
            self::writeError($output, $scan['report']->dataSourceFailureMessage());
        }

        return $exitCode->value;
    }

    /**
     * Load config, run the checks, and print expired ignore rules and a
     * token found in `excubia.json` to stderr.
     * `excubia push` calls this so both commands scan a project the same way.
     *
     * @param  array{
     *     format?: string|null,
     *     output?: string|null,
     *     fail-on?: string|null,
     *     only?: string|null,
     *     skip?: string|null,
     *     no-dev?: bool,
     *     offline?: bool,
     *     no-cache?: bool,
     *     config?: string|null,
     *     no-banner?: bool,
     *     strict?: bool
     * }  $options
     * @return array{report: HealthReportData, configuration: ExcubiaConfigurationData}
     *
     * @throws InvalidConfigurationException
     * @throws LockfileNotFoundException
     */
    public function scan(?string $path, array $options, OutputInterface $output): array
    {
        $projectRoot = $this->projectRoot($path);
        $today = $this->today ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $configuration = $this->configuration($projectRoot, $options);
        self::warnAboutTokenInFile($configuration, $output);
        $result = (new HealthCheckRunnerService($this->httpClient($output), $today))->run(
            $projectRoot,
            $configuration,
            $this->checkIds($options['only'] ?? null),
            $this->checkIds($options['skip'] ?? null),
            (bool) ($options['offline'] ?? false),
            (bool) ($options['no-cache'] ?? false),
            $this->laravelPosture,
        );
        $report = $result['report']->withRelease((new ReleaseService($this->environment))->resolve(
            $configuration->release,
            $this->laravelPosture === null ? $projectRoot : null,
        ));

        foreach ($result['expiredIgnoreWarnings'] as $warning) {
            self::writeError($output, $warning);
        }

        if ($this->reportListener !== null) {
            ($this->reportListener)($report);
        }

        return ['report' => $report, 'configuration' => $configuration];
    }

    /**
     * Merge file config with CLI overrides (`--fail-on`, `--no-dev`, `--strict`).
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidConfigurationException
     */
    private function configuration(string $projectRoot, array $options): ExcubiaConfigurationData
    {
        $loaded = (new ExcubiaConfigurationLoader)->load(
            isset($options['config']) && is_string($options['config']) ? $options['config'] : null,
            $projectRoot,
        );
        $failOn = $loaded->failOnSeverity;

        if (isset($options['fail-on']) && is_string($options['fail-on'])) {
            $failOn = $this->failOn($options['fail-on']);
        }

        $includeDev = ($options['no-dev'] ?? false) === true ? false : $loaded->isDevelopmentDependencyIncluded;
        // `--strict` can only turn strict mode on; `strict: true` in the file cannot be turned off from the CLI.
        $isStrict = ($options['strict'] ?? false) === true || $loaded->isStrict;

        if ($failOn === $loaded->failOnSeverity && $includeDev === $loaded->isDevelopmentDependencyIncluded && $isStrict === $loaded->isStrict) {
            return $loaded;
        }

        return new ExcubiaConfigurationData(
            failOnSeverity: $failOn,
            isDevelopmentDependencyIncluded: $includeDev,
            staleAfterMonths: $loaded->staleAfterMonths,
            phpWarnDaysBeforeEndOfLife: $loaded->phpWarnDaysBeforeEndOfLife,
            laravelWarnDaysBeforeEndOfLife: $loaded->laravelWarnDaysBeforeEndOfLife,
            isNodeEndOfLifeCheckEnabled: $loaded->isNodeEndOfLifeCheckEnabled,
            ignoreRules: $loaded->ignoreRules,
            cacheDirectory: $loaded->cacheDirectory,
            cacheTtlHours: $loaded->cacheTtlHours,
            appUrl: $loaded->appUrl,
            appToken: $loaded->appToken,
            isStrict: $isStrict,
            isHealthEndpointEnabled: $loaded->isHealthEndpointEnabled,
            healthEndpointPath: $loaded->healthEndpointPath,
            schedulerHeartbeatStaleAfterMinutes: $loaded->schedulerHeartbeatStaleAfterMinutes,
            failedJobHighSeverityAfterHours: $loaded->failedJobHighSeverityAfterHours,
            runtime: $loaded->runtime,
            exceptions: $loaded->exceptions,
            skippedPackagePatterns: $loaded->skippedPackagePatterns,
            release: $loaded->release,
        );
    }

    /**
     * Injected client, or Symfony; wraps with request logging when `-v` or higher.
     */
    public function httpClient(OutputInterface $output): HttpClientContract
    {
        $httpClient = $this->httpClient ?? new SymfonyHttpClientService;

        if ($output->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE) {
            return $httpClient;
        }

        return new NotifyingHttpClientService($httpClient, function (string $line) use ($output): void {
            self::writeError($output, $line);
        });
    }

    /**
     * Absolute project root: explicit path, or the process working directory.
     *
     * @throws InvalidConfigurationException
     */
    private function projectRoot(?string $path): string
    {
        if ($path === null || $path === '') {
            $cwd = getcwd();

            if ($cwd === false) {
                throw new InvalidConfigurationException('The working directory could not be read.');
            }

            return $cwd;
        }

        return $path;
    }

    /**
     * Split a comma-separated `--only` / `--skip` value into check ids.
     *
     * @return list<string>|null
     */
    private function checkIds(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if ($value === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', $value) as $id) {
            $id = trim($id);

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Parse `--fail-on`. `none` means null (never fail the process on findings).
     *
     * @throws InvalidConfigurationException
     */
    private function failOn(string $value): ?SeverityEnum
    {
        if ($value === 'none') {
            return null;
        }

        $severity = SeverityEnum::tryFrom($value);

        if ($severity === null || $severity === SeverityEnum::Info) {
            throw new InvalidConfigurationException('--fail-on must be critical, high, medium, low, or none.');
        }

        return $severity;
    }

    /**
     * Pick a renderer for `--format`. Table uses decoration only when writing to stdout.
     * SARIF gets the project-relative file that declares the Node version.
     *
     * @throws InvalidConfigurationException
     */
    private function renderer(?string $format, OutputInterface $output, ?string $outputFile, ?string $path): ReportRendererContract
    {
        $selected = $format ?? ReportFormatEnum::Table->value;
        $reportFormat = ReportFormatEnum::tryFrom($selected);

        if ($reportFormat === null) {
            throw new InvalidConfigurationException('--format must be table, json, markdown, or sarif.');
        }

        if ($reportFormat === ReportFormatEnum::Json) {
            return new JsonReportRenderer;
        }

        if ($reportFormat === ReportFormatEnum::Sarif) {
            return new SarifRenderer((new ProjectSnapshotService)->nodeVersionManifest($this->projectRoot($path)));
        }

        if ($reportFormat === ReportFormatEnum::Markdown) {
            return new MarkdownReportRenderer;
        }

        return new TableReportRenderer($outputFile === null && $output->isDecorated());
    }

    /**
     * Write the rendered report to stdout or `--output`, optionally with the banner.
     *
     * @throws InvalidConfigurationException
     */
    private function writeReport(string $rendered, ?string $outputFile, bool $isBannerHidden, OutputInterface $output): void
    {
        if (! $isBannerHidden && $output->getVerbosity() > OutputInterface::VERBOSITY_QUIET) {
            $banner = $this->banner();

            if ($banner !== '') {
                $output->writeln($banner);
            }
        }

        if ($outputFile === null) {
            $output->write($rendered);

            return;
        }

        if (file_put_contents($outputFile, $rendered) === false) {
            throw new InvalidConfigurationException('The report could not be written to '.$outputFile.'.');
        }
    }

    public static function warnAboutTokenInFile(ExcubiaConfigurationData $configuration, OutputInterface $output): void
    {
        if ($configuration->appToken !== null) {
            self::writeError($output, self::APP_TOKEN_IN_FILE_WARNING);
        }
    }

    /**
     * Prefer stderr when the output supports it so report stdout stays clean.
     */
    public static function writeError(OutputInterface $output, string $message): void
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($message);

            return;
        }

        $output->writeln($message);
    }

    /**
     * Brand banner from `brand/excubia/cli/banner.txt`, or empty when missing.
     */
    private function banner(): string
    {
        $path = dirname(__DIR__, 2).'/brand/excubia/cli/banner.txt';

        if (! is_file($path)) {
            return '';
        }

        $banner = file_get_contents($path);

        if ($banner === false) {
            return '';
        }

        return rtrim($banner);
    }

    /**
     * Read a string CLI option; reject non-string values from the input definition.
     *
     * @throws InvalidConfigurationException
     */
    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidConfigurationException('--'.$name.' must be a string.');
        }

        return $value;
    }
}
