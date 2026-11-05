<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Data\UpgradeStepData;
use Qiick\Excubia\Enums\FindingStatusEnum;

/**
 * Turns findings into numbered upgrade steps.
 *
 * Order is security fixes, PHP end of life, next-major blockers, the Laravel
 * major, then stale and abandoned replacements. Steps are grouped so the plan
 * reads as one list: one step per vulnerable package, one step naming every
 * next-major blocker, one step per abandoned package and one step for all
 * stale packages. Identical steps are dropped. The next Laravel major is the
 * locked major plus one. Steps are informational and are not findings, so
 * they never change the exit code. node-eol is not in this order.
 */
final class UpgradePlanService
{
    /**
     * @param  list<FindingData>  $findings
     * @return list<UpgradeStepData>
     */
    public function plan(ProjectSnapshotData $projectSnapshot, array $findings): array
    {
        $actionableFindings = array_values(array_filter(
            $findings,
            static fn (FindingData $finding): bool => $finding->status === FindingStatusEnum::Fail
                || $finding->status === FindingStatusEnum::Warn,
        ));
        $nextLaravelMajor = $this->nextLaravelMajor($projectSnapshot->laravelFrameworkVersion);

        $messages = [
            ...$this->advisoryMessages($this->findingsFor($actionableFindings, ['composer-advisories', 'npm-advisories'])),
            ...$this->fixHints($this->findingsFor($actionableFindings, ['php-eol'])),
            ...$this->blockerMessages($this->findingsFor($actionableFindings, ['laravel-next-blocker']), $nextLaravelMajor),
            ...$this->fixHints($this->findingsFor($actionableFindings, ['laravel-eol'])),
            ...$this->replacementMessages(
                $this->findingsFor($actionableFindings, ['composer-abandoned']),
                $this->findingsFor($actionableFindings, ['composer-stale']),
            ),
        ];
        $steps = [];

        foreach (array_values(array_unique($messages)) as $index => $message) {
            $steps[] = new UpgradeStepData($index + 1, $message);
        }

        return $steps;
    }

    /**
     * Locked major plus one. `v10.48.22` is 11. There is no fixed target.
     */
    public function nextLaravelMajor(?string $lockedVersion): ?int
    {
        if ($lockedVersion === null) {
            return null;
        }

        if (preg_match('/^v?(\d+)\./i', $lockedVersion, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] + 1;
    }

    /**
     * @param  list<FindingData>  $findings
     * @param  list<string>  $checkIds
     * @return list<FindingData>
     */
    private function findingsFor(array $findings, array $checkIds): array
    {
        return array_values(array_filter(
            $findings,
            static fn (FindingData $finding): bool => in_array($finding->checkId, $checkIds, true),
        ));
    }

    /**
     * @param  list<FindingData>  $findings
     * @return list<string>
     */
    private function fixHints(array $findings): array
    {
        return array_map(static fn (FindingData $finding): string => trim($finding->fixHint), $findings);
    }

    /**
     * One step per vulnerable package. A single advisory keeps its own hint,
     * several collapse into "fixes N advisories".
     *
     * @param  list<FindingData>  $findings
     * @return list<string>
     */
    private function advisoryMessages(array $findings): array
    {
        /** @var array<string, array{packageName: string, fixHint: string, advisories: array<string, true>}> $packages */
        $packages = [];

        foreach ($findings as $finding) {
            $key = $finding->checkId.' '.$finding->subject;
            $packages[$key] ??= ['packageName' => $finding->subject, 'fixHint' => trim($finding->fixHint), 'advisories' => []];
            $packages[$key]['advisories'][$finding->advisoryId ?? $finding->fixHint] = true;
        }

        $messages = [];

        foreach ($packages as $package) {
            $advisoryCount = count($package['advisories']);

            $messages[] = $advisoryCount === 1
                ? $package['fixHint']
                : 'Update '.$package['packageName'].' to ≥ fixed version (fixes '.$advisoryCount.' advisories).';
        }

        return $messages;
    }

    /**
     * One step naming every package that blocks the next Laravel major.
     *
     * @param  list<FindingData>  $findings
     * @return list<string>
     */
    private function blockerMessages(array $findings, ?int $nextLaravelMajor): array
    {
        if ($findings === []) {
            return [];
        }

        $target = $nextLaravelMajor === null ? 'The next Laravel major' : 'Laravel '.$nextLaravelMajor;

        return [$target.' is blocked by '.$this->packageList($findings).'; no release supports it yet: watch, replace or fork.'];
    }

    /**
     * One step per abandoned package, then one step for every stale package
     * that is not already abandoned.
     *
     * @param  list<FindingData>  $abandonedFindings
     * @param  list<FindingData>  $staleFindings
     * @return list<string>
     */
    private function replacementMessages(array $abandonedFindings, array $staleFindings): array
    {
        $messages = [];
        $abandonedPackages = [];

        foreach ($abandonedFindings as $finding) {
            $abandonedPackages[] = $finding->subject;
            $messages[] = $finding->subject.' is abandoned. '.ucfirst(rtrim(trim($finding->fixHint), '.')).'.';
        }

        $staleFindings = array_values(array_filter(
            $staleFindings,
            static fn (FindingData $finding): bool => ! in_array($finding->subject, $abandonedPackages, true),
        ));

        if ($staleFindings !== []) {
            $messages[] = 'Consider maintained alternatives to stale or archived packages: '.$this->packageList($staleFindings).'.';
        }

        return $messages;
    }

    /**
     * @param  list<FindingData>  $findings
     */
    private function packageList(array $findings): string
    {
        return implode(', ', array_values(array_unique(array_map(
            static fn (FindingData $finding): string => $finding->subject,
            $findings,
        ))));
    }
}
