<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Data\UpgradeStepData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;

/**
 * Turns findings into numbered upgrade steps.
 *
 * Order is security fixes, PHP end of life, next-major blockers, the Laravel
 * major, stale and abandoned replacements, Composer patch updates, npm patch
 * updates, then the license review. Steps are grouped so the plan reads as
 * one list: one step per vulnerable package, one step naming every
 * next-major blocker, one step per abandoned package, one step for all stale
 * packages, one step for every Composer direct dependency that is behind on
 * a patch and has no advisory (`composer-outdated`), the same for npm
 * (`npm-outdated` without an `npm-advisories` finding, #176), and one step
 * when `dependency-licenses` has a `fail` row, naming the denied packages
 * and any strong copyleft ones. New kinds of steps are appended, so the
 * existing ones keep their order. Identical steps are dropped. The next
 * Laravel major is the locked major plus one. Steps are informational and
 * are not findings, so they never change the exit code. node-eol is not in
 * this order.
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
            ...$this->patchUpdateMessages(
                'Update to the latest patch: ',
                $this->findingsFor($actionableFindings, ['composer-outdated']),
                $this->findingsFor($actionableFindings, ['composer-advisories']),
            ),
            ...$this->patchUpdateMessages(
                'Update npm packages to the latest patch: ',
                $this->findingsFor($actionableFindings, ['npm-outdated']),
                $this->findingsFor($actionableFindings, ['npm-advisories']),
            ),
            ...$this->licenseReviewMessages($this->findingsFor($actionableFindings, ['dependency-licenses'])),
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
     * One step naming the newest patch of every outdated package that has
     * no advisory finding of the same ecosystem; a vulnerable package already
     * has its own step.
     *
     * @param  list<FindingData>  $outdatedFindings
     * @param  list<FindingData>  $advisoryFindings
     * @return list<string>
     */
    private function patchUpdateMessages(string $prefix, array $outdatedFindings, array $advisoryFindings): array
    {
        $vulnerablePackages = array_map(static fn (FindingData $finding): string => $finding->subject, $advisoryFindings);
        $updates = [];

        foreach ($outdatedFindings as $finding) {
            $latestPatch = $finding->details['latestPatch'] ?? null;

            if (! is_string($latestPatch) || in_array($finding->subject, $vulnerablePackages, true)) {
                continue;
            }

            $updates[] = $finding->subject.' '.$latestPatch;
        }

        if ($updates === []) {
            return [];
        }

        return [$prefix.implode(', ', $updates).'.'];
    }

    /**
     * One step when at least one license is denied (a `fail` row). It names
     * the denied packages and, in the same step, the strong copyleft ones
     * (the `warn` rows at medium); unknown licenses (low) are left out.
     * Without a denied license there is no step.
     *
     * @param  list<FindingData>  $findings
     * @return list<string>
     */
    private function licenseReviewMessages(array $findings): array
    {
        $deniedFindings = array_values(array_filter(
            $findings,
            static fn (FindingData $finding): bool => $finding->status === FindingStatusEnum::Fail,
        ));

        if ($deniedFindings === []) {
            return [];
        }

        $copyleftFindings = array_values(array_filter(
            $findings,
            static fn (FindingData $finding): bool => $finding->status === FindingStatusEnum::Warn
                && $finding->severity === SeverityEnum::Medium,
        ));
        $message = 'Review dependency licenses: denied for '.$this->packageList($deniedFindings);

        if ($copyleftFindings !== []) {
            $message .= '; strong copyleft for '.$this->packageList($copyleftFindings);
        }

        return [$message.'. Replace the packages or clear the licenses after a legal review.'];
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
