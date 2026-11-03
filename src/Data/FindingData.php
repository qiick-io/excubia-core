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
     * True when this row is a check skipped because a data source failed.
     */
    public function isDataSourceFailure(): bool
    {
        return $this->status === FindingStatusEnum::Skipped && $this->skipReason === SkipReasonEnum::DataSourceFailure;
    }
}
