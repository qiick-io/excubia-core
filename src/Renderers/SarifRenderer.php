<?php

declare(strict_types=1);

namespace Qiick\Excubia\Renderers;

use Qiick\Excubia\Contracts\ReportRendererContract;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\HealthReportData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;

/**
 * SARIF 2.1.0 log for GitHub code scanning (D6).
 *
 * One rule per check id and one result per `fail` or `warn` finding. Pass and
 * skipped rows produce no result. Locations are manifest paths relative to
 * the scanned project, never absolute paths or hostnames (SPEC section 7).
 * The upgrade plan is not part of the log: it is advice, not a finding.
 */
final class SarifRenderer implements ReportRendererContract
{
    public const SCHEMA_URI = 'https://docs.oasis-open.org/sarif/sarif/v2.1.0/errata01/os/schemas/sarif-schema-2.1.0.json';

    private const HELP_URI = 'https://github.com/qiick-io/excubia-core#health-checks';

    private const FINGERPRINT_KEY = 'excubiaFinding/v1';

    /**
     * Manifest each check reads its findings from. `node-eol` is resolved by
     * the caller because the Node declaration can live in several files;
     * `npm-advisories` and `npm-outdated` use the report's
     * `javascriptLockfile` (#21).
     * The `laravel-*` posture checks read the booted app, so they point at
     * the standard Laravel file where the fix usually starts.
     */
    private const MANIFEST_BY_CHECK_ID = [
        'php-eol' => 'composer.json',
        'laravel-eol' => 'composer.lock',
        'composer-advisories' => 'composer.lock',
        'composer-abandoned' => 'composer.lock',
        'composer-stale' => 'composer.lock',
        'laravel-next-blocker' => 'composer.lock',
        'laravel-debug-mode' => 'config/app.php',
        'laravel-production-caches' => 'config/app.php',
        'laravel-pending-migrations' => 'database/migrations',
        'laravel-failed-jobs' => 'config/queue.php',
        'laravel-scheduler-heartbeat' => 'routes/console.php',
        'laravel-maintenance-mode' => 'config/app.php',
        'laravel-app-key' => 'config/app.php',
        'laravel-session-security' => 'config/session.php',
        'laravel-cors-wildcard' => 'config/cors.php',
        'laravel-production-drivers' => 'config/app.php',
        'laravel-log-level' => 'config/logging.php',
        'laravel-dev-packages' => 'composer.json',
        'laravel-database' => 'config/database.php',
        'laravel-cache' => 'config/cache.php',
        'laravel-storage' => 'storage',
        'laravel-disk-space' => 'storage',
        'laravel-queue-backlog' => 'config/queue.php',
        'php-platform-requirements' => 'composer.lock',
        'env-example-sync' => '.env.example',
        'composer-outdated' => 'composer.lock',
        'laravel-queue-workers' => 'config/queue.php',
        'laravel-backups' => 'config/backup.php',
        'laravel-database-connections' => 'config/database.php',
        'laravel-database-size' => 'config/database.php',
        'laravel-redis-memory' => 'config/database.php',
        'php-opcache' => 'composer.json',
        'php-production-ini' => 'composer.json',
        'laravel-octane-config' => 'config/octane.php',
        'laravel-reverb' => 'config/broadcasting.php',
    ];

    /**
     * @param  string  $nodeVersionManifest  `.nvmrc`, `.node-version` or `package.json`, relative to the project
     */
    public function __construct(private readonly string $nodeVersionManifest = 'package.json') {}

    public function render(HealthReportData $healthReport): string
    {
        return json_encode(
            $this->toArray($healthReport),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /**
     * The SARIF log as an array, one run with Excubia as the tool driver.
     *
     * @return array{
     *     '$schema': string,
     *     version: string,
     *     runs: list<array{
     *         tool: array{driver: array{name: string, informationUri: string, rules: list<array<string, mixed>>}},
     *         results: list<array<string, mixed>>
     *     }>
     * }
     */
    public function toArray(HealthReportData $healthReport): array
    {
        $rules = [];
        $ruleIndexes = [];
        $results = [];

        foreach ($healthReport->checkResults as $checkResult) {
            $ruleIndexes[$checkResult->checkId] = count($rules);
            $rules[] = [
                'id' => $checkResult->checkId,
                'name' => $checkResult->name,
                'shortDescription' => ['text' => $checkResult->name],
                'helpUri' => self::HELP_URI,
            ];
        }

        foreach ($healthReport->findings() as $finding) {
            if ($finding->status !== FindingStatusEnum::Fail && $finding->status !== FindingStatusEnum::Warn) {
                continue;
            }

            $results[] = $this->result($finding, $ruleIndexes[$finding->checkId] ?? null, $healthReport->javascriptLockfile ?? 'package-lock.json');
        }

        return [
            '$schema' => self::SCHEMA_URI,
            'version' => '2.1.0',
            'runs' => [
                [
                    'tool' => [
                        'driver' => [
                            'name' => 'Excubia',
                            'informationUri' => 'https://github.com/qiick-io/excubia-core',
                            'rules' => $rules,
                        ],
                    ],
                    'results' => $results,
                ],
            ],
        ];
    }

    /**
     * One SARIF result. A finding whose check has no rule (not produced by the
     * runner, only by hand-built reports) keeps its `ruleId` without an index.
     *
     * @param  string  $javascriptLockfile  the lockfile the npm findings come from (#21)
     * @return array<string, mixed>
     */
    private function result(FindingData $finding, ?int $ruleIndex, string $javascriptLockfile): array
    {
        $result = ['ruleId' => $finding->checkId];

        if ($ruleIndex !== null) {
            $result['ruleIndex'] = $ruleIndex;
        }

        $properties = [
            'severity' => $finding->severity->value,
            'status' => $finding->status->value,
            'subject' => $finding->subject,
        ];

        if ($finding->advisoryId !== null) {
            $properties['advisoryId'] = $finding->advisoryId;
        }

        if ($finding->evidenceUrl !== null) {
            $properties['evidenceUrl'] = $finding->evidenceUrl;
        }

        return $result + [
            'level' => $this->level($finding->severity),
            'message' => ['text' => $this->messageText($finding)],
            'locations' => [
                [
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => $this->manifest($finding, $javascriptLockfile)],
                        'region' => ['startLine' => 1],
                    ],
                ],
            ],
            'partialFingerprints' => [self::FINGERPRINT_KEY => $finding->fingerprint()],
            'properties' => $properties,
        ];
    }

    /**
     * Critical and high are errors, medium is a warning, low and info are notes.
     */
    private function level(SeverityEnum $severity): string
    {
        return match ($severity) {
            SeverityEnum::Critical, SeverityEnum::High => 'error',
            SeverityEnum::Medium => 'warning',
            SeverityEnum::Low, SeverityEnum::Info => 'note',
        };
    }

    private function messageText(FindingData $finding): string
    {
        $text = $finding->subject.': '.$finding->message;

        if ($finding->fixHint === '') {
            return $text;
        }

        return $text.' '.$finding->fixHint;
    }

    /**
     * `npm-advisories` and `npm-outdated` point at the JavaScript lockfile the report read,
     * `dependency-licenses` at the lockfile of the finding's ecosystem;
     * every other check at its fixed manifest.
     */
    private function manifest(FindingData $finding, string $javascriptLockfile): string
    {
        if ($finding->checkId === 'npm-advisories' || $finding->checkId === 'npm-outdated') {
            return $javascriptLockfile;
        }

        if ($finding->checkId === 'node-eol') {
            return $this->nodeVersionManifest;
        }

        if ($finding->checkId === 'dependency-licenses') {
            return ($finding->details['ecosystem'] ?? null) === 'npm' ? $javascriptLockfile : 'composer.lock';
        }

        return self::MANIFEST_BY_CHECK_ID[$finding->checkId] ?? 'composer.lock';
    }
}
