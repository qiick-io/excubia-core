<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Enums\SkipReasonEnum;

/**
 * One row of a health report.
 *
 * The required fields are the v0.1.0 finding shape. `advisoryId` is optional
 * so an ignore rule can name one advisory. It is omitted from the required
 * JSON schema fields. `skipReason` is set on skipped rows only and tells a
 * data-source failure apart from a deliberate skip.
 */
final readonly class FindingData
{
    public function __construct(
        public string $checkId,
        public SeverityEnum $severity,
        public FindingStatusEnum $status,
        public string $subject,
        public string $message,
        public string $fixHint,
        public ?string $evidenceUrl,
        public ?string $advisoryId = null,
        public ?SkipReasonEnum $skipReason = null,
    ) {}

    /**
     * One skipped row naming the private packages a check did not send to
     * `$dataSource`, or no row when there are none.
     *
     * The names come from the local lockfile and stay in the report; the
     * subject is the fixed `private packages` so an ignore rule can target
     * the row. `not-applicable`, so `--strict` does not exit 3.
     *
     * @param  list<string>  $packageNames
     * @return list<self>
     */
    public static function privatePackagesSkipped(string $checkId, array $packageNames, string $dataSource): array
    {
        if ($packageNames === []) {
            return [];
        }

        $count = count($packageNames);

        return [new self(
            checkId: $checkId,
            severity: SeverityEnum::Info,
            status: FindingStatusEnum::Skipped,
            subject: 'private packages',
            message: $count.' private '.($count === 1 ? 'package' : 'packages').' not sent to '.$dataSource.': '.implode(', ', $packageNames).'.',
            fixHint: '',
            evidenceUrl: null,
            skipReason: SkipReasonEnum::NotApplicable,
        )];
    }

    /**
     * True when this row is a check skipped because a data source failed.
     */
    public function isDataSourceFailure(): bool
    {
        return $this->status === FindingStatusEnum::Skipped && $this->skipReason === SkipReasonEnum::DataSourceFailure;
    }
}
