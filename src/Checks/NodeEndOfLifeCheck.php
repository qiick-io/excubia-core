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
 * Reports when the declared Node version is end of life, or close to it.
 *
 * The declaration is already chosen as `.nvmrc`, then `.node-version`, then
 * `package.json` `engines.node`. This check does not add another rule for
 * disagreements. 180 days is the warn window. The config file only has an
 * enabled flag for this check.
 */
final class NodeEndOfLifeCheck implements HealthCheckContract
{
    private const WARN_DAYS = 180;

    public function __construct(
        private readonly EndOfLifeApiService $endOfLifeApi,
        private readonly DateTimeImmutable $today,
        private readonly CalendarDateService $calendarDate = new CalendarDateService,
    ) {}

    public function id(): string
    {
        return 'node-eol';
    }

    public function name(): string
    {
        return 'Node end of life';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        if (! $configuration->isNodeEndOfLifeCheckEnabled) {
            return CheckResultData::skipped($this->id(), $this->name(), 'node', 'The node-eol check is disabled.');
        }

        $declaration = $projectSnapshot->nodeVersionDeclaration;

        if ($declaration === null || trim($declaration) === '') {
            return CheckResultData::skipped($this->id(), $this->name(), 'node', 'Node version is not declared.');
        }

        $majorVersion = $this->majorVersion($declaration);

        if ($majorVersion === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'node', 'The declared Node version could not be read as a major version.');
        }

        $cycles = $this->endOfLifeApi->cyclesIfAvailable('nodejs');

        if ($cycles === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'node', 'endoflife.date did not respond.');
        }

        $cycle = $this->calendarDate->matchingCycle($majorVersion, $cycles);

        if ($cycle === null) {
            return CheckResultData::skipped($this->id(), $this->name(), 'node', 'No end-of-life cycle matches the declared Node version.');
        }

        $today = $this->today->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        $ends = $cycle->securitySupportEndDate();
        $fixHint = 'Move to Node LTS '.$this->recommendedLts($cycles, $today).'.';

        if ($ends !== null && $today > $ends) {
            return $this->singleFindingResult(FindingStatusEnum::Fail, SeverityEnum::High, 'Node '.$majorVersion.' is end of life.', $fixHint);
        }

        if ($ends !== null && $this->calendarDate->daysUntil($today, $ends) <= self::WARN_DAYS) {
            return $this->singleFindingResult(FindingStatusEnum::Warn, SeverityEnum::Low, 'Maintenance for Node '.$majorVersion.' ends on '.$ends->format('Y-m-d').'.', $fixHint);
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), []);
    }

    /**
     * One finding for the `node` subject.
     */
    private function singleFindingResult(FindingStatusEnum $status, SeverityEnum $severity, string $message, string $fixHint): CheckResultData
    {
        return CheckResultData::fromFindings($this->id(), $this->name(), [
            new FindingData($this->id(), $severity, $status, 'node', $message, $fixHint, 'https://endoflife.date/nodejs'),
        ]);
    }

    /**
     * First digit group in a Node declaration (`.nvmrc`, engines, etc.).
     */
    private function majorVersion(string $declaration): ?string
    {
        if (preg_match('/(\d+)/', $declaration, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Newest LTS cycle that still has security support on `$today`.
     *
     * @param  list<EndOfLifeCycleData>  $cycles
     */
    private function recommendedLts(array $cycles, DateTimeImmutable $today): string
    {
        $recommended = null;

        foreach ($cycles as $cycle) {
            if (! $cycle->isLongTermSupport) {
                continue;
            }

            $ends = $cycle->securitySupportEndDate();

            if ($ends !== null && $today > $ends) {
                continue;
            }

            if ($recommended === null || version_compare($cycle->cycle, $recommended, '>')) {
                $recommended = $cycle->cycle;
            }
        }

        return $recommended ?? 'a current LTS';
    }
}
