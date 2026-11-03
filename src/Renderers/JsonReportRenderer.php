<?php

declare(strict_types=1);

namespace Qiick\Excubia\Renderers;

use DateTimeZone;
use Qiick\Excubia\Contracts\ReportRendererContract;
use Qiick\Excubia\Data\HealthReportData;

/**
 * JSON report for schema version 1.
 *
 * Optional fields, all additive (still schema version 1): `generatedAt`,
 * `excubiaVersion` and `project` when the report carries them;
 * per finding `fingerprint` always, `advisoryId` when the finding names
 * one, `skipReason` on skipped rows and `details` when the check set any.
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
     * @return array<string, mixed>
     */
    public function toArray(HealthReportData $healthReport): array
    {
        $checks = [];

        foreach ($healthReport->checkResults as $checkResult) {
            $findings = [];

            foreach ($checkResult->findings as $finding) {
                $row = [
                    'checkId' => $finding->checkId,
                    'severity' => $finding->severity->value,
                    'status' => $finding->status->value,
                    'subject' => $finding->subject,
                    'message' => $finding->message,
                    'fixHint' => $finding->fixHint,
                    'evidenceUrl' => $finding->evidenceUrl,
                    'fingerprint' => $finding->fingerprint(),
                ];

                if ($finding->advisoryId !== null) {
                    $row['advisoryId'] = $finding->advisoryId;
                }

                if ($finding->skipReason !== null) {
                    $row['skipReason'] = $finding->skipReason->value;
                }

                if ($finding->details !== []) {
                    $row['details'] = $finding->details;
                }

                $findings[] = $row;
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

        $document = ['schemaVersion' => $healthReport->schemaVersion];

        if ($healthReport->generatedAt !== null) {
            $document['generatedAt'] = $healthReport->generatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }

        if ($healthReport->excubiaVersion !== null) {
            $document['excubiaVersion'] = $healthReport->excubiaVersion;
        }

        if ($healthReport->project !== null) {
            $document['project'] = [
                'php' => $healthReport->project->php,
                'laravel' => $healthReport->project->laravel,
                'node' => $healthReport->project->node,
            ];
        }

        return $document + [
            'checks' => $checks,
            'upgradePlan' => $upgradePlan,
        ];
    }
}
