<?php

declare(strict_types=1);

namespace Qiick\Excubia\Renderers;

use DateTimeImmutable;
use DateTimeZone;
use Qiick\Excubia\Contracts\ReportRendererContract;
use Qiick\Excubia\Data\EndOfLifeCycleData;
use Qiick\Excubia\Data\HealthReportData;
use Qiick\Excubia\Data\LicenseInventoryData;

/**
 * JSON report for schema version 1.
 *
 * Optional fields, all additive (still schema version 1): `generatedAt`,
 * `excubiaVersion`, `release`, `deployedAt`, `project` and
 * `javascriptLockfile` (#21) when the report carries them, `licenses` when
 * `dependency-licenses` ran, `schedule` (#146) from the Artisan commands;
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
            $document['generatedAt'] = self::timestamp($healthReport->generatedAt);
        }

        if ($healthReport->excubiaVersion !== null) {
            $document['excubiaVersion'] = $healthReport->excubiaVersion;
        }

        if ($healthReport->release !== null) {
            $document['release'] = $healthReport->release;
        }

        if ($healthReport->deployedAt !== null) {
            $document['deployedAt'] = self::timestamp($healthReport->deployedAt);
        }

        if ($healthReport->project !== null) {
            $document['project'] = [
                'php' => $this->runtime($healthReport->project->php, $healthReport->project->phpCycle),
                'laravel' => $this->runtime($healthReport->project->laravel, $healthReport->project->laravelCycle),
                'node' => $this->runtime($healthReport->project->node, $healthReport->project->nodeCycle),
            ];
        }

        if ($healthReport->javascriptLockfile !== null) {
            $document['javascriptLockfile'] = $healthReport->javascriptLockfile;
        }

        $document += [
            'checks' => $checks,
            'upgradePlan' => $upgradePlan,
        ];
        $licenses = $healthReport->licenses();

        if ($licenses !== null) {
            $document['licenses'] = $this->licenses($licenses);
        }

        if ($healthReport->schedule !== null) {
            $document['schedule'] = $healthReport->schedule;
        }

        return $document;
    }

    /**
     * The top-level `licenses` inventory. Counts are lists of
     * `{license, packageCount}` sorted by id, so an empty inventory and an
     * id made of digits encode the same way in every JSON decoder.
     *
     * @return array{
     *     packageCount: int,
     *     byLicense: list<array{license: string, packageCount: int}>,
     *     byEcosystem: array<string, array{packageCount: int, byLicense: list<array{license: string, packageCount: int}>}>
     * }
     */
    private function licenses(LicenseInventoryData $licenses): array
    {
        $byEcosystem = [];

        foreach ($licenses->ecosystems as $ecosystem => $inventory) {
            $byEcosystem[$ecosystem] = [
                'packageCount' => $inventory['packageCount'],
                'byLicense' => $this->licenseCounts($inventory['byLicense']),
            ];
        }

        return [
            'packageCount' => $licenses->packageCount(),
            'byLicense' => $this->licenseCounts($licenses->byLicense()),
            'byEcosystem' => $byEcosystem,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{license: string, packageCount: int}>
     */
    private function licenseCounts(array $counts): array
    {
        $rows = [];

        foreach ($counts as $license => $packageCount) {
            $rows[] = ['license' => (string) $license, 'packageCount' => $packageCount];
        }

        return $rows;
    }

    private static function timestamp(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * One `project` entry: null without a version, otherwise the version
     * with its matched cycle and support end dates (null when unknown).
     *
     * @return array{version: string, cycle: string|null, securitySupportEndsOn: string|null, activeSupportEndsOn: string|null}|null
     */
    private function runtime(?string $version, ?EndOfLifeCycleData $cycle): ?array
    {
        if ($version === null) {
            return null;
        }

        return ['version' => $version] + ($cycle?->findingDetails() ?? [
            'cycle' => null,
            'securitySupportEndsOn' => null,
            'activeSupportEndsOn' => null,
        ]);
    }
}
