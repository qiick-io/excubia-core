<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Enums\SkipReasonEnum;

/**
 * Everything one check contributes to the report.
 *
 * The status is the worst finding status so a renderer can tell that the
 * check failed without scanning the rows. Fail outranks warn, warn outranks
 * skipped, and skipped outranks pass. An empty finding list is a pass.
 */
final readonly class CheckResultData
{
    /**
     * @param  list<FindingData>  $findings
     */
    public function __construct(
        public string $checkId,
        public string $name,
        public FindingStatusEnum $status,
        public array $findings,
    ) {}

    /**
     * Build a result and set the status from the findings.
     *
     * Checks use this so they do not each invent a different rollup.
     *
     * @param  list<FindingData>  $findings
     */
    public static function fromFindings(string $checkId, string $name, array $findings): self
    {
        return new self($checkId, $name, self::combinedStatus($findings), $findings);
    }

    /**
     * A check that did not run. The reason is the finding message.
     *
     * Severity is info so a skipped check cannot trip `--fail-on`. A check
     * whose data source did not answer passes `SkipReasonEnum::DataSourceFailure`
     * so `--strict` can see it; the runner turns that into `Offline` under
     * `--offline`, where no request is allowed.
     */
    public static function skipped(
        string $checkId,
        string $name,
        string $subject,
        string $reason,
        SkipReasonEnum $skipReason = SkipReasonEnum::NotApplicable,
    ): self {
        return self::fromFindings($checkId, $name, [
            new FindingData(
                checkId: $checkId,
                severity: SeverityEnum::Info,
                status: FindingStatusEnum::Skipped,
                subject: $subject,
                message: $reason,
                fixHint: '',
                evidenceUrl: null,
                skipReason: $skipReason,
            ),
        ]);
    }

    /**
     * @param  list<FindingData>  $findings
     */
    private static function combinedStatus(array $findings): FindingStatusEnum
    {
        $status = FindingStatusEnum::Pass;

        foreach ($findings as $finding) {
            if ($finding->status === FindingStatusEnum::Fail) {
                return FindingStatusEnum::Fail;
            }

            if ($finding->status === FindingStatusEnum::Warn) {
                $status = FindingStatusEnum::Warn;
            } elseif ($finding->status === FindingStatusEnum::Skipped && $status === FindingStatusEnum::Pass) {
                $status = FindingStatusEnum::Skipped;
            }
        }

        return $status;
    }
}
