<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use DateTimeImmutable;
use DateTimeZone;
use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\GitHubRepositoryData;
use Qiick\Excubia\Data\PackagistVersionData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\CalendarDateService;
use Qiick\Excubia\Services\GitHubRepositoryApiService;
use Qiick\Excubia\Services\PackagistApiService;

/**
 * Fails when the upstream GitHub repository is archived, and warns when the
 * newest release is older than the configured number of months.
 */
final class ComposerStaleCheck implements HealthCheckContract
{
    public function __construct(
        private readonly PackagistApiService $packagistApi,
        private readonly GitHubRepositoryApiService $gitHubRepositoryApi,
        private readonly DateTimeImmutable $today,
        private readonly CalendarDateService $calendarDate = new CalendarDateService,
    ) {}

    public function id(): string
    {
        return 'composer-stale';
    }

    public function name(): string
    {
        return 'Stale packages';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $findings = [];
        $today = $this->today->setTimezone(new DateTimeZone('UTC'));

        foreach ($projectSnapshot->composerPackages as $package) {
            $versions = $this->packagistApi->versionsIfAvailable($package->name);

            if ($versions === null) {
                return CheckResultData::skipped($this->id(), $this->name(), $package->name, 'Packagist metadata did not respond.');
            }

            $latest = $this->latestRelease($versions);
            $repository = $this->gitHubRepository($latest);

            if ($repository === false) {
                return CheckResultData::skipped($this->id(), $this->name(), $package->name, 'GitHub did not respond.');
            }

            if ($repository !== null && $repository->isArchived) {
                $findings[] = $this->finding($package->name, FindingStatusEnum::Fail, SeverityEnum::High, 'The GitHub repository is archived.');

                continue;
            }

            $releasedAt = $latest === null ? null : $this->calendarDate->parseUtcDate($latest->releasedAt);

            if ($releasedAt !== null && $releasedAt < $today->modify('-'.$configuration->staleAfterMonths.' months')) {
                $findings[] = $this->finding($package->name, FindingStatusEnum::Warn, SeverityEnum::Low, 'No release for more than '.$configuration->staleAfterMonths.' months.');
            }
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    private function finding(string $packageName, FindingStatusEnum $status, SeverityEnum $severity, string $message): FindingData
    {
        return new FindingData(
            checkId: $this->id(),
            severity: $severity,
            status: $status,
            subject: $packageName,
            message: $message,
            fixHint: 'Consider a maintained alternative.',
            evidenceUrl: 'https://packagist.org/packages/'.$packageName,
        );
    }

    /**
     * Newest non-dev Packagist version by semver, or null when none exist.
     *
     * @param  list<PackagistVersionData>  $versions
     */
    private function latestRelease(array $versions): ?PackagistVersionData
    {
        $latest = null;

        foreach ($versions as $version) {
            if (str_starts_with($version->version, 'dev-')) {
                continue;
            }

            if ($latest === null || version_compare(ltrim($version->version, 'vV'), ltrim($latest->version, 'vV'), '>')) {
                $latest = $version;
            }
        }

        return $latest;
    }

    /**
     * Resolve the GitHub repository for a Packagist version's source URL.
     *
     * @return GitHubRepositoryData|null|false null when there is no GitHub repository, false when GitHub did not respond
     */
    private function gitHubRepository(?PackagistVersionData $version): mixed
    {
        if ($version === null || $version->sourceUrl === null) {
            return null;
        }

        if (preg_match('#^https://github\.com/([^/]+)/([^/.]+)#', $version->sourceUrl, $matches) !== 1) {
            return null;
        }

        $repository = $this->gitHubRepositoryApi->repositoryIfAvailable($matches[1], $matches[2]);

        if ($repository === null) {
            return false;
        }

        return $repository;
    }
}
