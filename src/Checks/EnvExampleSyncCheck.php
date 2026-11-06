<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;

/**
 * Configuration drift between `.env.example` and `config/*.php` (#13).
 *
 * A key that a config file reads with `env('KEY')` but `.env.example` does
 * not list warns at low, one row per key: new developers and deployments
 * miss it. Keys that `.env.example` lists but no config file reads are one
 * `info` summary row, so the report stays short. Keys matching
 * `checks.env-example-sync.ignoreKeys` (and the documented defaults) are
 * left out. Key names only: `.env` is never read and no value is reported.
 * Skipped (`not-applicable`) without `.env.example` or `config/`.
 */
final class EnvExampleSyncCheck implements HealthCheckContract
{
    private const UNUSED_SUBJECT = 'unused keys';

    private const EVIDENCE_URL = 'https://laravel.com/docs/configuration#environment-configuration';

    public function id(): string
    {
        return 'env-example-sync';
    }

    public function name(): string
    {
        return '.env.example sync';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $environmentKeys = $projectSnapshot->environmentKeys;

        if ($environmentKeys?->exampleKeys === null) {
            return CheckResultData::skipped($this->id(), $this->name(), '.env.example', 'No .env.example in the project.');
        }

        if ($environmentKeys->configKeys === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'config', 'No config directory in the project.');
        }

        $settings = $configuration->envExampleSync;
        $exampleKeys = array_flip($environmentKeys->exampleKeys);
        $findings = [];

        foreach ($environmentKeys->configKeys as $key => $configFiles) {
            if (isset($exampleKeys[$key]) || $settings->isIgnored($key)) {
                continue;
            }

            $findings[] = new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::Low,
                status: FindingStatusEnum::Warn,
                subject: $key,
                message: $key.' is read by '.implode(', ', $configFiles).' but missing from .env.example.',
                fixHint: 'Add '.$key.'= to .env.example (empty or with a safe example value), or add it to checks.env-example-sync.ignoreKeys.',
                evidenceUrl: self::EVIDENCE_URL,
                details: ['key' => $key, 'configFiles' => $configFiles],
            );
        }

        $unusedKeys = [];

        foreach ($environmentKeys->exampleKeys as $key) {
            if (! isset($environmentKeys->configKeys[$key]) && ! $settings->isIgnored($key)) {
                $unusedKeys[] = $key;
            }
        }

        if ($unusedKeys !== []) {
            $count = count($unusedKeys);

            $findings[] = new FindingData(
                checkId: $this->id(),
                severity: SeverityEnum::Info,
                status: FindingStatusEnum::Warn,
                subject: self::UNUSED_SUBJECT,
                message: $count.' '.($count === 1 ? 'key' : 'keys').' in .env.example not read by config/*.php: '.implode(', ', $unusedKeys).'.',
                fixHint: 'Remove keys nothing reads from .env.example, or add the ones read outside config/ to checks.env-example-sync.ignoreKeys.',
                evidenceUrl: self::EVIDENCE_URL,
                details: ['keys' => $unusedKeys],
            );
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }
}
