<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Illuminate\Console\Command;
use Qiick\Excubia\Console\PushCommand;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * `php artisan excubia:push`. Same engine as `vendor/bin/excubia push`,
 * with the path fixed to the application base path.
 *
 * ponytail: the URL and token come from the process environment or
 * `excubia.json`, not from Laravel config, so `config:cache` does not
 * carry them; add a published config file if that becomes a need.
 */
final class ExcubiaPushCommand extends Command
{
    protected $signature = 'excubia:push
        {--config= : Config file}
        {--strict : Do not send, exit 3, when a data source failure skipped a check}';

    protected $description = 'Run the checks and send the report to your excubia-app.';

    public function handle(): int
    {
        $config = $this->option('config');

        if ($config !== null && ! is_string($config)) {
            throw new InvalidConfigurationException('--config must be a string.');
        }

        return (new PushCommand)->runWithOptions(
            ExcubiaCheckCommand::applicationBasePath($this->getLaravel()),
            ['config' => $config === '' ? null : $config, 'strict' => (bool) $this->option('strict')],
            $this->output,
        );
    }
}
