<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use DateTimeImmutable;
use DateTimeZone;
use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\EndOfLifeCycleData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\CalendarDateService;
use Qiick\Excubia\Services\EndOfLifeApiService;

/**
 * Reports when the resolved PHP version is out of security support, or close to it.
 */
final class PhpEndOfLifeCheck implements HealthCheckContract
{
    public function __construct(
        private readonly EndOfLifeApiService $endOfLifeApi,
        private readonly DateTimeImmutable $today,
        private readonly CalendarDateService $calendarDate = new CalendarDateService,
    ) {}

    public function id(): string
    {
        return 'php-eol';
    }

    public function name(): string
    {
        return 'PHP end of life';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        if ($projectSnapshot->phpVersion === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'php', 'PHP version is not declared.');
        }

        $cycles = $this->endOfLifeApi->cyclesIfAvailable('php');

        if ($cycles === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'php', 'endoflife.date did not respond.');
        }

        $cycle = $this->matchingPhpCycle($projectSnapshot->phpVersion, $cycles);

        if ($cycle === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'php', 'No end-of-life cycle matches the resolved PHP version.');
        }

        $today = $this->today->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        $securityEnds = $cycle->securitySupportEndDate();
        $activeEnds = $cycle->activeSupportEndDate();

        if ($securityEnds !== null && $today > $securityEnds) {
            return $this->failed($cycle, $cycles, $today);
        }

        $isSecuritySupportEndingSoon = $securityEnds !== null && $this->calendarDate->daysUntil($today, $securityEnds) <= $configuration->phpWarnDaysBeforeEndOfLife;
        $hasActiveSupportEnded = $activeEnds !== null && $today > $activeEnds;

        if ($hasActiveSupportEnded && $isSecuritySupportEndingSoon) {
            return CheckResultData::fromFindings($this->id(), $this->name(), [
                $this->finding(
                    FindingStatusEnum::Warn,
                    SeverityEnum::Medium,
                    'Active support ended. Security support ends '.$securityEnds->format('Y-m-d').'.',
                    $this->fixHint($cycles, $today),
                ),
            ]);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), []);
    }

    /**
     * Critical failure when security support has already ended.
     *
     * @param  list<EndOfLifeCycleData>  $cycles
     */
    private function failed(EndOfLifeCycleData $cycle, array $cycles, DateTimeImmutable $today): CheckResultData
    {
        $endedOn = $cycle->securitySupportEndsOn ?? 'an unknown date';

        return CheckResultData::fromFindings($this->id(), $this->name(), [
            $this->finding(
                FindingStatusEnum::Fail,
                SeverityEnum::Critical,
                'Security support ended on '.$endedOn.'.',
                $this->fixHint($cycles, $today),
            ),
        ]);
    }

    private function finding(FindingStatusEnum $status, SeverityEnum $severity, string $message, string $fixHint): FindingData
    {
        return new FindingData(
            checkId: $this->id(),
            severity: $severity,
            status: $status,
            subject: 'php',
            message: $message,
            fixHint: $fixHint,
            evidenceUrl: 'https://endoflife.date/php',
        );
    }

    /**
     * Match a resolved PHP version (e.g. `8.1.2`) to its `major.minor` cycle.
     *
     * @param  list<EndOfLifeCycleData>  $cycles
     */
    private function matchingPhpCycle(string $phpVersion, array $cycles): ?EndOfLifeCycleData
    {
        if (preg_match('/^v?(\d+)\.(\d+)/i', $phpVersion, $matches) !== 1) {
            return null;
        }

        return $this->calendarDate->matchingCycle($matches[1].'.'.$matches[2], $cycles);
    }

    /**
     * Prefer the newest cycle that still has security support on `$today`.
     *
     * @param  list<EndOfLifeCycleData>  $cycles
     */
    private function fixHint(array $cycles, DateTimeImmutable $today): string
    {
        $recommended = null;

        foreach ($cycles as $cycle) {
            $securityEnds = $cycle->securitySupportEndDate();

            if ($securityEnds !== null && $today > $securityEnds) {
                continue;
            }

            if ($recommended === null || version_compare($cycle->cycle, $recommended->cycle, '>')) {
                $recommended = $cycle;
            }
        }

        if ($recommended === null) {
            return 'Upgrade PHP to a supported release.';
        }

        $until = $recommended->securitySupportEndsOn ?? 'a later date';

        return 'Upgrade PHP to '.$recommended->cycle.' (supported until '.$until.').';
    }
}
