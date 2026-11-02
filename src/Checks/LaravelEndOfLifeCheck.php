<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use DateTimeImmutable;
use DateTimeZone;
use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\CalendarDateService;
use Qiick\Excubia\Services\EndOfLifeApiService;

/**
 * Reports when the locked Laravel version is out of security fixes, or close to it.
 */
final class LaravelEndOfLifeCheck implements HealthCheckContract
{
    public function __construct(
        private readonly EndOfLifeApiService $endOfLifeApi,
        private readonly DateTimeImmutable $today,
        private readonly CalendarDateService $calendarDate = new CalendarDateService,
    ) {}

    public function id(): string
    {
        return 'laravel-eol';
    }

    public function name(): string
    {
        return 'Laravel end of life';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        if ($projectSnapshot->laravelFrameworkVersion === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'laravel/framework', 'laravel/framework is not locked.');
        }

        $cycles = $this->endOfLifeApi->cyclesIfAvailable('laravel');

        if ($cycles === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'laravel/framework', 'endoflife.date did not respond.');
        }

        $majorVersion = $this->majorVersion($projectSnapshot->laravelFrameworkVersion);
        $cycle = $majorVersion === null ? null : $this->calendarDate->matchingCycle($majorVersion, $cycles);

        if ($cycle === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'laravel/framework', 'No end-of-life cycle matches the locked Laravel version.');
        }

        $today = $this->today->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        $securityEnds = $cycle->securitySupportEndDate();
        $bugFixesEnd = $cycle->activeSupportEndDate();
        $nextMajor = (string) ((int) $majorVersion + 1);
        $securityDate = $securityEnds?->format('Y-m-d') ?? 'an unknown date';
        $fixHint = 'Upgrade to Laravel '.$nextMajor.'; security fixes for your version end '.$securityDate.'.';

        if ($securityEnds !== null && $today > $securityEnds) {
            return $this->singleFindingResult(FindingStatusEnum::Fail, SeverityEnum::Critical, 'Security fixes ended on '.$securityDate.'.', $fixHint);
        }

        $haveBugFixesEnded = $bugFixesEnd !== null && $today > $bugFixesEnd;
        $isSecuritySupportEndingSoon = $securityEnds !== null && $this->calendarDate->daysUntil($today, $securityEnds) <= $configuration->laravelWarnDaysBeforeEndOfLife;

        if ($haveBugFixesEnded || $isSecuritySupportEndingSoon) {
            return $this->singleFindingResult(FindingStatusEnum::Warn, SeverityEnum::Medium, 'Bug fixes ended or security fixes end within '.$configuration->laravelWarnDaysBeforeEndOfLife.' days.', $fixHint);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), []);
    }

    /**
     * One finding for the locked laravel/framework subject.
     */
    private function singleFindingResult(FindingStatusEnum $status, SeverityEnum $severity, string $message, string $fixHint): CheckResultData
    {
        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData($this->id(), $severity, $status, 'laravel/framework', $message, $fixHint, 'https://endoflife.date/laravel'),
        ]);
    }

    /**
     * Leading major digit from a locked Composer version (`v12.0.1` → `12`).
     */
    private function majorVersion(string $lockedVersion): ?string
    {
        if (preg_match('/^v?(\d+)/i', $lockedVersion, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
