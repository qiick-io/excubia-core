<?php

declare(strict_types=1);

namespace Qiick\Excubia\Renderers;

use Qiick\Excubia\Contracts\ReportRendererContract;
use Qiick\Excubia\Data\HealthReportData;

/**
 * JSON report for schema version 1. `advisoryId` is not emitted: the spec
 * does not say the rendered report prints that field.
 */
final class JsonReportRenderer implements ReportRendererContract
{
    public function render(HealthReportData $healthReport): string
    {
        return json_encode(
            $this->toArray($healthReport),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /**
     * The report as `docs/report-schema.json` describes it. `excubia push`
     * sends this array inside the push envelope.
     *
     * @return array{
     *     schemaVersion: int,
     *     checks: list<array{checkId: string, name: string, status: string, findings: list<array<string, string|null>>}>,
     *     upgradePlan: list<array{stepNumber: int, message: string}>
     * }
     */
    public function toArray(HealthReportData $healthReport): array
    {
        $checks = [];

        foreach ($healthReport->checkResults as $checkResult) {
            $findings = [];

            foreach ($checkResult->findings as $finding) {
                $findings[] = [
                    'checkId' => $finding->checkId,
                    'severity' => $finding->severity->value,
                    'status' => $finding->status->value,
                    'subject' => $finding->subject,
                    'message' => $finding->message,
                    'fixHint' => $finding->fixHint,
                    'evidenceUrl' => $finding->evidenceUrl,
                ];
            }

            $checks[] = [
                'checkId' => $checkResult->checkId,
                'name' => $checkResult->name,
                'status' => $checkResult->status->value,
                'findings' => $findings,
            ];
        }

        $upgradePlan = [];

        foreach ($healthReport->upgradeSteps as $upgradeStep) {
            $upgradePlan[] = [
                'stepNumber' => $upgradeStep->stepNumber,
                'message' => $upgradeStep->message,
            ];
        }

        return [
            'schemaVersion' => $healthReport->schemaVersion,
            'checks' => $checks,
            'upgradePlan' => $upgradePlan,
        ];
    }
}
