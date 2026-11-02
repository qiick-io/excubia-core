<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use DateTimeImmutable;
use Qiick\Excubia\Checks\ComposerAbandonedCheck;
use Qiick\Excubia\Checks\ComposerAdvisoriesCheck;
use Qiick\Excubia\Checks\ComposerStaleCheck;
use Qiick\Excubia\Checks\LaravelEndOfLifeCheck;
use Qiick\Excubia\Checks\LaravelNextMajorBlockerCheck;
use Qiick\Excubia\Checks\NodeEndOfLifeCheck;
use Qiick\Excubia\Checks\NpmAdvisoriesCheck;
use Qiick\Excubia\Checks\PhpEndOfLifeCheck;
use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Contracts\HttpClientContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\HealthReportData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Exceptions\DataSourceUnavailableException;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;
use Qiick\Excubia\Exceptions\LockfileNotFoundException;
use Qiick\Excubia\Registries\HealthCheckRegistry;

/**
 * Runs the spec's checks in order, applies ignore rules, and builds the report.
 *
 * A data-source outage becomes a skipped check. `--strict` is not
 * implemented, so that outage does not become exit code 3. This service
 * does not write the report and does not print warnings.
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
    ): array {
        return $this->runForSnapshot(
            $this->projectSnapshot->snapshot($projectRoot, $configuration),
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
                $checkResults[] = $healthCheck->run($snapshot, $configuration);
            } catch (DataSourceUnavailableException $exception) {
                $checkResults[] = CheckResultData::skipped(
                    $healthCheck->id(),
                    $healthCheck->name(),
                    $healthCheck->id(),
                    $exception->getMessage(),
                );
            }
        }

        $raw = new HealthReportData($checkResults, []);
        $report = $raw->withoutIgnoredFindings($configuration, $this->today);

        return [
            'report' => new HealthReportData(
                $report->checkResults,
                $this->upgradePlan->plan($snapshot, $report->findings()),
            ),
            'expiredIgnoreWarnings' => $configuration->expiredIgnoreWarnings($raw->findings(), $this->today),
        ];
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
        ];
    }
}
