<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

use DateTimeImmutable;
use Qiick\Excubia\Enums\ExitCodeEnum;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;

/**
 * The document renderers and the exit-code rule share.
 *
 * `schemaVersion` is always 1 for this milestone. Ignore rules are applied
 * by `withoutIgnoredFindings` before `exitCodeFor` is called. Upgrade steps
 * are kept as given; the upgrade-plan service rebuilds them from the
 * findings that remain.
 */
final readonly class HealthReportData
{
    public const SCHEMA_VERSION = 1;

    public int $schemaVersion;

    /**
     * @param  list<CheckResultData>  $checkResults
     * @param  list<UpgradeStepData>  $upgradeSteps
     */
    public function __construct(
        public array $checkResults,
        public array $upgradeSteps,
    ) {
        $this->schemaVersion = self::SCHEMA_VERSION;
    }

    /**
     * The human summary from the spec. Counts fail and warn findings only.
     */
    public function summaryLine(): string
    {
        $counts = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];

        foreach ($this->findings() as $finding) {
            if ($finding->status !== FindingStatusEnum::Fail && $finding->status !== FindingStatusEnum::Warn) {
                continue;
            }

            $severity = $finding->severity->value;

            if (! isset($counts[$severity])) {
                continue;
            }

            $counts[$severity]++;
        }

        return $counts['critical'].' critical · '.$counts['high'].' high · '.$counts['medium'].' medium · '.$counts['low'].' low';
    }

    /**
     * Every finding, in check order. Pass rows are included.
     *
     * @return list<FindingData>
     */
    public function findings(): array
    {
        $findings = [];

        foreach ($this->checkResults as $checkResult) {
            foreach ($checkResult->findings as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Drop findings covered by an active ignore rule.
     *
     * Does not print the expired-rule warning. Call
     * `ExcubiaConfigurationData::expiredIgnoreWarnings` for that text.
     * Does not read the clock itself: `$today` is passed in so tests stay
     * deterministic.
     */
    public function withoutIgnoredFindings(ExcubiaConfigurationData $configuration, DateTimeImmutable $today): self
    {
        $checkResults = [];

        foreach ($this->checkResults as $checkResult) {
            $remainingFindings = [];

            foreach ($checkResult->findings as $finding) {
                if ($configuration->isIgnored($finding, $today)) {
                    continue;
                }

                $remainingFindings[] = $finding;
            }

            $checkResults[] = CheckResultData::fromFindings(
                $checkResult->checkId,
                $checkResult->name,
                $remainingFindings,
            );
        }

        return new self($checkResults, $this->upgradeSteps);
    }

    /**
     * Exit code from fail and warn findings at or above `$failOnSeverity`.
     *
     * Null means `--fail-on=none`: findings never return 1. Pass, skipped,
     * and info-only rows do not count. Upgrade steps do not count. Ignored
     * findings must already have been removed. With `$isStrict`, a check
     * skipped because a data source failed returns 3, unless a finding
     * already returns 1 (CI sees real findings first).
     */
    public function exitCodeFor(?SeverityEnum $failOnSeverity, bool $isStrict = false): ExitCodeEnum
    {
        if ($failOnSeverity !== null && $this->hasFindingAtOrAbove($failOnSeverity)) {
            return ExitCodeEnum::FindingsAtOrAboveFailOn;
        }

        if ($isStrict && $this->dataSourceFailures() !== []) {
            return ExitCodeEnum::DataSourceFailure;
        }

        return ExitCodeEnum::NoFindingsAtOrAboveFailOn;
    }

    private function hasFindingAtOrAbove(SeverityEnum $failOnSeverity): bool
    {
        foreach ($this->findings() as $finding) {
            if ($finding->status !== FindingStatusEnum::Fail && $finding->status !== FindingStatusEnum::Warn) {
                continue;
            }

            if ($finding->severity->isAtLeastAsSevereAs($failOnSeverity)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Skipped rows whose data source failed. Deliberate skips (`--offline`,
     * a check that does not apply) are not included.
     *
     * @return list<FindingData>
     */
    public function dataSourceFailures(): array
    {
        $failures = [];

        foreach ($this->findings() as $finding) {
            if ($finding->isDataSourceFailure()) {
                $failures[] = $finding;
            }
        }

        return $failures;
    }

    /**
     * The `--strict` error: one line per check that a data source failure
     * skipped, naming the source through the skip message.
     */
    public function dataSourceFailureMessage(): string
    {
        $lines = ['--strict: checks were skipped because a data source failed:'];

        foreach ($this->dataSourceFailures() as $finding) {
            $lines[] = '  '.$finding->checkId.' ('.$finding->subject.'): '.$finding->message;
        }

        return implode("\n", $lines);
    }
}
