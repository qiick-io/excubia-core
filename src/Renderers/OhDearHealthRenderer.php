<?php

declare(strict_types=1);

namespace Qiick\Excubia\Renderers;

use DateTimeImmutable;
use Qiick\Excubia\Contracts\ReportRendererContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\HealthReportData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SkipReasonEnum;

/**
 * Oh Dear application health JSON (`docs/health-endpoint.md`): `finishedAt`
 * as a Unix timestamp and one `checkResults` entry per check.
 *
 * Status mapping: pass → ok, warn → warning, fail → failed, skipped →
 * skipped. A run that produced no report (an exception or a configuration
 * error) is the single `excubia-run` entry with status `crashed`.
 * Messages are built from check ids, severities and package names only,
 * never from finding text, so no path, hostname or token reaches the output.
 */
final class OhDearHealthRenderer implements ReportRendererContract
{
    private const SUBJECTS_IN_MESSAGE = 5;

    public function __construct(private readonly DateTimeImmutable $finishedAt) {}

    public function render(HealthReportData $healthReport): string
    {
        $checkResults = [];

        foreach ($healthReport->checkResults as $checkResult) {
            $checkResults[] = $this->checkResult($checkResult);
        }

        return $this->encode($checkResults);
    }

    /**
     * The last scheduled run did not produce a report.
     */
    public function renderCrashed(): string
    {
        return $this->encode([[
            'name' => 'excubia-run',
            'label' => 'Excubia run',
            'status' => 'crashed',
            'notificationMessage' => 'The last Excubia run did not finish. Run php artisan excubia:check to see the error.',
            'shortSummary' => 'Crashed',
            'meta' => [],
        ]]);
    }

    /**
     * No run has stored a result yet.
     */
    public function renderNotRunYet(): string
    {
        return $this->encode([[
            'name' => 'excubia-run',
            'label' => 'Excubia run',
            'status' => 'skipped',
            'notificationMessage' => 'No Excubia result is stored yet: the scheduler has not run excubia:check or excubia:push.',
            'shortSummary' => 'Not run yet',
            'meta' => [],
        ]]);
    }

    /**
     * @return array{name: string, label: string, status: string, notificationMessage: string, shortSummary: string, meta: array<string, int|string>}
     */
    private function checkResult(CheckResultData $checkResult): array
    {
        $problems = array_values(array_filter(
            $checkResult->findings,
            static fn (FindingData $finding): bool => $finding->status === FindingStatusEnum::Fail || $finding->status === FindingStatusEnum::Warn,
        ));

        return [
            'name' => $checkResult->checkId,
            'label' => $checkResult->name,
            'status' => self::status($checkResult->status),
            'notificationMessage' => $this->notificationMessage($checkResult, $problems),
            'shortSummary' => $this->shortSummary($checkResult->status, count($problems)),
            'meta' => $this->meta($problems),
        ];
    }

    private static function status(FindingStatusEnum $status): string
    {
        return match ($status) {
            FindingStatusEnum::Pass => 'ok',
            FindingStatusEnum::Warn => 'warning',
            FindingStatusEnum::Fail => 'failed',
            FindingStatusEnum::Skipped => 'skipped',
        };
    }

    /**
     * @param  list<FindingData>  $problems
     */
    private function notificationMessage(CheckResultData $checkResult, array $problems): string
    {
        if ($checkResult->status === FindingStatusEnum::Skipped) {
            return $this->skipMessage($checkResult);
        }

        if ($problems === []) {
            return '';
        }

        $subjects = array_values(array_unique(array_map(static fn (FindingData $finding): string => $finding->subject, $problems)));
        $listed = array_slice($subjects, 0, self::SUBJECTS_IN_MESSAGE);
        $hidden = count($subjects) - count($listed);
        $highest = $this->highestSeverity($problems);

        return sprintf(
            '%s (highest severity: %s): %s%s.',
            $this->findingCount(count($problems)),
            $highest,
            implode(', ', $listed),
            $hidden > 0 ? ' and '.$hidden.' more' : '',
        );
    }

    private function skipMessage(CheckResultData $checkResult): string
    {
        $skipReason = $checkResult->findings[0]->skipReason ?? SkipReasonEnum::NotApplicable;

        return match ($skipReason) {
            SkipReasonEnum::DataSourceFailure => 'Skipped: a data source did not respond.',
            SkipReasonEnum::Offline => 'Skipped: no cached data for an offline run.',
            SkipReasonEnum::NotApplicable => 'Skipped: the check does not apply to this project.',
        };
    }

    private function shortSummary(FindingStatusEnum $status, int $problemCount): string
    {
        return match ($status) {
            FindingStatusEnum::Pass => 'OK',
            FindingStatusEnum::Skipped => 'Skipped',
            FindingStatusEnum::Warn, FindingStatusEnum::Fail => $this->findingCount($problemCount),
        };
    }

    /**
     * @param  list<FindingData>  $problems
     * @return array<string, int|string>
     */
    private function meta(array $problems): array
    {
        if ($problems === []) {
            return ['findingCount' => 0];
        }

        return ['findingCount' => count($problems), 'highestSeverity' => $this->highestSeverity($problems)];
    }

    /**
     * @param  non-empty-list<FindingData>  $problems
     */
    private function highestSeverity(array $problems): string
    {
        $highest = $problems[0]->severity;

        foreach ($problems as $finding) {
            if ($finding->severity->weight() > $highest->weight()) {
                $highest = $finding->severity;
            }
        }

        return $highest->value;
    }

    private function findingCount(int $count): string
    {
        return $count.($count === 1 ? ' finding' : ' findings');
    }

    /**
     * @param  list<array<string, mixed>>  $checkResults
     */
    private function encode(array $checkResults): string
    {
        return json_encode(
            ['finishedAt' => $this->finishedAt->getTimestamp(), 'checkResults' => $checkResults],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
