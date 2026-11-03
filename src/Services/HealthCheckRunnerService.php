<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Composer\InstalledVersions;
use DateTimeImmutable;
use Qiick\Excubia\Checks\ComposerAbandonedCheck;
use Qiick\Excubia\Checks\ComposerAdvisoriesCheck;
use Qiick\Excubia\Checks\ComposerStaleCheck;
use Qiick\Excubia\Checks\LaravelDebugModeCheck;
use Qiick\Excubia\Checks\LaravelEndOfLifeCheck;
use Qiick\Excubia\Checks\LaravelFailedJobsCheck;
use Qiick\Excubia\Checks\LaravelMaintenanceModeCheck;
use Qiick\Excubia\Checks\LaravelNextMajorBlockerCheck;
use Qiick\Excubia\Checks\LaravelPendingMigrationsCheck;
use Qiick\Excubia\Checks\LaravelProductionCachesCheck;
use Qiick\Excubia\Checks\LaravelSchedulerHeartbeatCheck;
use Qiick\Excubia\Checks\NodeEndOfLifeCheck;
use Qiick\Excubia\Checks\NpmAdvisoriesCheck;
use Qiick\Excubia\Checks\PhpEndOfLifeCheck;
use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\HealthReportData;
use Qiick\Excubia\Data\LaravelPostureData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Data\ProjectVersionsData;
use Qiick\Excubia\Enums\SkipReasonEnum;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Exceptions\LockfileNotFoundException;
use Qiick\Excubia\Registries\HealthCheckRegistry;

/**
 * Runs the spec's checks in order, applies ignore rules, and builds the report.
 *
 * A data-source outage becomes a skipped check marked
 * `SkipReasonEnum::DataSourceFailure` (or `Offline` under `--offline`);
 * the command decides whether `--strict` turns it into exit code 3. This
 * service does not write the report and does not print warnings.
 */
final class HealthCheckRunnerService
{
    public function __construct(
        private readonly HttpClientContract $httpClient,
        private readonly DateTimeImmutable $today,
        private readonly ProjectSnapshotService $projectSnapshot = new ProjectSnapshotService,
        private readonly UpgradePlanService $upgradePlan = new UpgradePlanService,
    ) {}

    /**
     * `$laravelPosture` is passed by the Artisan commands only; without it
     * the `laravel-*` posture checks are skipped ("requires Artisan").
     *
     * @param  list<string>|null  $onlyCheckIds
     * @param  list<string>|null  $skippedCheckIds
     * @return array{report: HealthReportData, expiredIgnoreWarnings: list<string>}
     *
     * @throws InvalidConfigurationException
     * @throws LockfileNotFoundException
     */
    public function run(
        string $projectRoot,
        ExcubiaConfigurationData $configuration,
        ?array $onlyCheckIds,
        ?array $skippedCheckIds,
        bool $isOffline,
        bool $isCacheBypassed,
        ?LaravelPostureData $laravelPosture = null,
    ): array {
        return $this->runForSnapshot(
            $this->projectSnapshot->snapshot($projectRoot, $configuration)->withLaravelPosture($laravelPosture),
            $configuration,
            $onlyCheckIds,
            $skippedCheckIds,
            $isOffline,
            $isCacheBypassed,
        );
    }

    /**
     * Runs the checks on values that were not read from a project directory.
     *
     * The web scanner receives package names and versions only, so it builds
     * the snapshot itself and never writes a lockfile to disk.
     *
     * @param  list<string>|null  $onlyCheckIds
     * @param  list<string>|null  $skippedCheckIds
     * @return array{report: HealthReportData, expiredIgnoreWarnings: list<string>}
     *
     * @throws InvalidConfigurationException
     */
    public function runForSnapshot(
        ProjectSnapshotData $snapshot,
        ExcubiaConfigurationData $configuration,
        ?array $onlyCheckIds,
        ?array $skippedCheckIds,
        bool $isOffline,
        bool $isCacheBypassed,
    ): array {
        $cache = new ResponseCacheService(
            $configuration->cacheDirectory,
            $configuration->cacheTtlHours,
            $isOffline,
            $isCacheBypassed,
        );
        $registry = new HealthCheckRegistry($this->healthChecks($cache));
        $checkResults = [];

        foreach ($registry->healthChecksFor($onlyCheckIds, $skippedCheckIds) as $healthCheck) {
            try {
                $checkResult = $healthCheck->run($snapshot, $configuration);
            } catch (DataSourceUnavailableException $exception) {
                $checkResult = CheckResultData::skipped(
                    $healthCheck->id(),
                    $healthCheck->name(),
                    $healthCheck->id(),
                    $exception->getMessage(),
                    SkipReasonEnum::DataSourceFailure,
                );
            }

            $checkResults[] = $isOffline ? $this->withOfflineSkips($checkResult) : $checkResult;
        }

        $raw = new HealthReportData($checkResults, [], $this->today, self::excubiaVersion(), ProjectVersionsData::fromSnapshot($snapshot));
        $report = $raw->withoutIgnoredFindings($configuration, $this->today);

        return [
            'report' => new HealthReportData(
                $report->checkResults,
                $this->upgradePlan->plan($snapshot, $report->findings()),
                $report->generatedAt,
                $report->excubiaVersion,
                $report->project,
            ),
            'expiredIgnoreWarnings' => $configuration->expiredIgnoreWarnings($raw->findings(), $this->today),
        ];
    }

    /**
     * The installed package version, the same value `bin/excubia --version`
     * prints; `dev` when Composer does not know it.
     */
    public static function excubiaVersion(): string
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled('qiick/excubia-core')) {
            return 'dev';
        }

        return InstalledVersions::getPrettyVersion('qiick/excubia-core') ?? 'dev';
    }

    /**
     * Under `--offline` no request is sent, so a data source that "did not
     * respond" is a cache miss the user asked for: a deliberate skip that
     * `--strict` must not turn into exit code 3.
     */
    private function withOfflineSkips(CheckResultData $checkResult): CheckResultData
    {
        $findings = [];

        foreach ($checkResult->findings as $finding) {
            if (! $finding->isDataSourceFailure()) {
                $findings[] = $finding;

                continue;
            }

            $findings[] = new FindingData(
                checkId: $finding->checkId,
                severity: $finding->severity,
                status: $finding->status,
                subject: $finding->subject,
                message: $finding->message,
                fixHint: $finding->fixHint,
                evidenceUrl: $finding->evidenceUrl,
                advisoryId: $finding->advisoryId,
                skipReason: SkipReasonEnum::Offline,
                details: $finding->details,
            );
        }

        return CheckResultData::fromFindings($checkResult->checkId, $checkResult->name, $findings);
    }

    /**
     * @return list<HealthCheckContract>
     */
    private function healthChecks(ResponseCacheService $cache): array
    {
        $endOfLife = new EndOfLifeApiService($this->httpClient, $cache);
        $packagist = new PackagistApiService($this->httpClient, $cache);
        $constraints = new ComposerConstraintService;

        return [
            new PhpEndOfLifeCheck($endOfLife, $this->today),
            new LaravelEndOfLifeCheck($endOfLife, $this->today),
            new NodeEndOfLifeCheck($endOfLife, $this->today),
            new ComposerAdvisoriesCheck($packagist, $constraints),
            new NpmAdvisoriesCheck(new OsvApiService($this->httpClient, $cache)),
            new ComposerAbandonedCheck($packagist),
            new ComposerStaleCheck($packagist, new GitHubRepositoryApiService($this->httpClient, $cache), $this->today),
            new LaravelNextMajorBlockerCheck($packagist, $constraints),
            new LaravelDebugModeCheck,
            new LaravelProductionCachesCheck,
            new LaravelPendingMigrationsCheck,
            new LaravelFailedJobsCheck,
            new LaravelSchedulerHeartbeatCheck,
            new LaravelMaintenanceModeCheck,
        ];
    }
}
