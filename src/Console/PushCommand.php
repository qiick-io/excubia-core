<?php

declare(strict_types=1);

namespace Qiick\Excubia\Console;

use DateTimeImmutable;
use DateTimeZone;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Enums\ExitCodeEnum;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Exceptions\LockfileNotFoundException;
use Qiick\Excubia\Services\ReportPushService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `excubia push`: run the checks like `excubia check` and send the report
 * to the user's own `excubia-app`. The Artisan alias calls `runWithOptions`.
 *
 * Exit codes: 0 sent (whatever the findings), 2 configuration error,
 * 3 the app rejected the token or did not accept the report, or, with
 * `--strict`, a data source failure skipped a check (nothing is sent).
 * `EXCUBIA_APP_URL` / `EXCUBIA_APP_TOKEN` win over the `app` keys of
 * `excubia.json`, so CI can override a committed file.
 */
final class PushCommand extends Command
{
    /**
     * @param  array<string, string>|null  $environment  tests pass variables here; null reads getenv()
     */
    public function __construct(
        private readonly ?HttpClientContract $httpClient = null,
        private readonly ?DateTimeImmutable $now = null,
        private readonly ?array $environment = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('push')
            ->setDescription('Run the checks and send the report to your excubia-app.')
            ->addArgument('path', InputArgument::OPTIONAL, 'Project directory')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Config file')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Do not send, exit 3, when a data source failure skipped a check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        $config = $input->getOption('config');

        return $this->runWithOptions(
            is_string($path) ? $path : null,
            ['config' => is_string($config) ? $config : null, 'strict' => (bool) $input->getOption('strict')],
            $output,
        );
    }

    /**
     * With `--strict` (or `strict: true`) a check skipped because a data
     * source failed stops the push before anything is sent: the app would
     * otherwise store an incomplete report as if it were the full picture.
     *
     * @param  array{config?: string|null, strict?: bool}  $options
     */
    public function runWithOptions(?string $path, array $options, OutputInterface $output): int
    {
        $now = $this->now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $checkCommand = new CheckCommand($this->httpClient, $now);

        try {
            $scan = $checkCommand->scan($path, ['config' => $options['config'] ?? null, 'strict' => $options['strict'] ?? false], $output);

            if ($scan['configuration']->isStrict && $scan['report']->dataSourceFailures() !== []) {
                CheckCommand::writeError($output, $scan['report']->dataSourceFailureMessage());
                CheckCommand::writeError($output, 'The report was not sent.');

                return ExitCodeEnum::DataSourceFailure->value;
            }

            $batchId = (new ReportPushService($checkCommand->httpClient($output)))->push(
                $scan['report'],
                $this->environmentValue('EXCUBIA_APP_URL') ?? $scan['configuration']->appUrl,
                $this->environmentValue('EXCUBIA_APP_TOKEN') ?? $scan['configuration']->appToken,
                $now,
            );
        } catch (InvalidConfigurationException|LockfileNotFoundException $exception) {
            CheckCommand::writeError($output, $exception->getMessage());

            return $exception->getCode();
        } catch (DataSourceUnavailableException $exception) {
            CheckCommand::writeError($output, $exception->getMessage());

            return ExitCodeEnum::DataSourceFailure->value;
        }

        $output->writeln('Report sent to excubia-app (batch '.$batchId.').');

        return self::SUCCESS;
    }

    private function environmentValue(string $name): ?string
    {
        $value = $this->environment !== null ? ($this->environment[$name] ?? null) : getenv($name);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
