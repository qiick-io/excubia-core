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
 * data-source failure apart from a deliberate skip. `details` holds the
 * check-specific values excubia-app reads instead of parsing the message
 * (keys per check id in `docs/report-schema.json`); empty means none.
 */
final readonly class FindingData
{
    /**
     * @param  array<string, string|int|bool|list<string>|null>  $details
     */
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
        public array $details = [],
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
            details: ['count' => $count],
        )];
    }

    /**
     * One warn row for a direct dependency with newer releases, shared by
     * `composer-outdated` and `npm-outdated`: behind on a major or a minor
     * is low, behind only on a patch is info. `$newer` comes from
     * `ComposerConstraintService::newerReleases()` with at least
     * `latestMinor` or `latestMajor` set.
     *
     * @param  array{latestPatch: string|null, latestMinor: string|null, latestMajor: string|null}  $newer
     */
    public static function outdatedPackage(string $checkId, string $packageName, string $lockedVersion, array $newer, string $evidenceUrl): self
    {
        $isMajorBehind = $newer['latestMajor'] !== null;
        $isMinorBehind = $newer['latestMinor'] !== null && $newer['latestMinor'] !== $newer['latestPatch'];

        if ($isMajorBehind) {
            $message = 'A new major is available: '.$newer['latestMajor'].'.';
            $fixHint = $newer['latestMinor'] === null
                ? 'Plan the upgrade to '.$newer['latestMajor'].'.'
                : 'Update to '.$newer['latestMinor'].', then plan the upgrade to '.$newer['latestMajor'].'.';
        } else {
            $message = ($isMinorBehind ? 'Behind on a minor' : 'Behind on a patch').': '.$newer['latestMinor'].' is available.';
            $fixHint = 'Update to '.$newer['latestMinor'].'.';
        }

        return new self(
            checkId: $checkId,
            severity: $isMajorBehind || $isMinorBehind ? SeverityEnum::Low : SeverityEnum::Info,
            status: FindingStatusEnum::Warn,
            subject: $packageName,
            message: $message,
            fixHint: $fixHint,
            evidenceUrl: $evidenceUrl,
            details: [
                'package' => $packageName,
                'lockedVersion' => $lockedVersion,
                'latestPatch' => $newer['latestPatch'],
                'latestMinor' => $newer['latestMinor'],
                'latestMajor' => $newer['latestMajor'],
                'isMajorBehind' => $isMajorBehind,
            ],
        );
    }

    /**
     * Stable identity of the finding across reports: sha256 of check id,
     * subject and advisory id joined by NUL. Messages and fix hints carry
     * dates and recommended versions that move over time, so they are left
     * out. The JSON `fingerprint` and the SARIF `partialFingerprints` entry
     * both use this value.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode("\0", [$this->checkId, $this->subject, $this->advisoryId ?? '']));
    }

    /**
     * True when this row is a check skipped because a data source failed.
     */
    public function isDataSourceFailure(): bool
    {
        return $this->status === FindingStatusEnum::Skipped && $this->skipReason === SkipReasonEnum::DataSourceFailure;
    }
}
