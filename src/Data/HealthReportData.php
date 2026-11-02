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
     * Null means `--fail-on=none` and always returns 0. Pass, skipped, and
     * info-only rows do not count. Upgrade steps do not count. Ignored
     * findings must already have been removed.
     */
    public function exitCodeFor(?SeverityEnum $failOnSeverity): ExitCodeEnum
    {
        if ($failOnSeverity === null) {
            return ExitCodeEnum::NoFindingsAtOrAboveFailOn;
        }

        foreach ($this->findings() as $finding) {
            if ($finding->status !== FindingStatusEnum::Fail && $finding->status !== FindingStatusEnum::Warn) {
                continue;
            }

            if ($finding->severity->isAtLeastAsSevereAs($failOnSeverity)) {
                return ExitCodeEnum::FindingsAtOrAboveFailOn;
            }
        }

        return ExitCodeEnum::NoFindingsAtOrAboveFailOn;
    }
}
