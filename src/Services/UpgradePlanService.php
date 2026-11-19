<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Data\UpgradeStepData;
use Qiick\Excubia\Data\UpgradeStepPackageData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Enums\UpgradeStepKindEnum;

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
 *
 * Each step is built as structured data first (#276): its kind
 * (`UpgradeStepKindEnum`), the Laravel target major and the packages it
 * names, with their ecosystem and versions. The message is written from the
 * same values, so the sentence and the structured fields cannot drift; steps
 * that repeat a check's fix hint (one advisory, PHP and Laravel end of life,
 * an abandoned package) keep the hint as their sentence and take their
 * fields from the same finding.
 *
 * @phpstan-type PlannedStep array{kind: UpgradeStepKindEnum, targetMajor: int|null, packages: list<UpgradeStepPackageData>, message: string}
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

        $plannedSteps = [
            ...$this->advisorySteps($this->findingsFor($actionableFindings, ['composer-advisories', 'npm-advisories'])),
            ...$this->fixHintSteps(
                UpgradeStepKindEnum::PhpEol,
                $this->findingsFor($actionableFindings, ['php-eol']),
                new UpgradeStepPackageData('php', 'composer', $projectSnapshot->phpVersion, null),
                null,
            ),
            ...$this->blockerSteps($this->findingsFor($actionableFindings, ['laravel-next-blocker']), $nextLaravelMajor),
            ...$this->fixHintSteps(
                UpgradeStepKindEnum::LaravelEol,
                $this->findingsFor($actionableFindings, ['laravel-eol']),
                new UpgradeStepPackageData('laravel/framework', 'composer', $projectSnapshot->laravelFrameworkVersion, null),
                $nextLaravelMajor,
            ),
            ...$this->replacementSteps(
                $this->findingsFor($actionableFindings, ['composer-abandoned']),
                $this->findingsFor($actionableFindings, ['composer-stale']),
            ),
            ...$this->patchUpdateSteps(
                UpgradeStepKindEnum::ComposerPatch,
                $this->findingsFor($actionableFindings, ['composer-outdated']),
                $this->findingsFor($actionableFindings, ['composer-advisories']),
            ),
            ...$this->patchUpdateSteps(
                UpgradeStepKindEnum::NpmPatch,
                $this->findingsFor($actionableFindings, ['npm-outdated']),
                $this->findingsFor($actionableFindings, ['npm-advisories']),
            ),
            ...$this->licenseReviewSteps($this->findingsFor($actionableFindings, ['dependency-licenses'])),
        ];
        $steps = [];
        $plannedMessages = [];

        foreach ($plannedSteps as $plannedStep) {
            if (isset($plannedMessages[$plannedStep['message']])) {
                continue;
            }

            $plannedMessages[$plannedStep['message']] = true;
            $steps[] = new UpgradeStepData(
                count($steps) + 1,
                $plannedStep['message'],
                $plannedStep['kind'],
                $plannedStep['targetMajor'],
                $plannedStep['packages'],
            );
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
     * One step per finding whose fix hint is the whole sentence (PHP and
     * Laravel end of life), naming the runtime package it is about.
     *
     * @param  list<FindingData>  $findings
     * @return list<PlannedStep>
     */
    private function fixHintSteps(UpgradeStepKindEnum $kind, array $findings, UpgradeStepPackageData $package, ?int $targetMajor): array
    {
        return array_map(
            fn (FindingData $finding): array => $this->plannedStep($kind, [$package], trim($finding->fixHint), $targetMajor),
            $findings,
        );
    }

    /**
     * One step per vulnerable package. A single advisory keeps its own hint,
     * several collapse into "fixes N advisories".
     *
     * ponytail: `target` stays null because an advisory range can list a
     * fixed version per branch; pick the lowest fixed version above the
     * locked one if a consumer needs it.
     *
     * @param  list<FindingData>  $findings
     * @return list<PlannedStep>
     */
    private function advisorySteps(array $findings): array
    {
        /** @var array<string, array{finding: FindingData, advisories: array<string, true>}> $packages */
        $packages = [];

        foreach ($findings as $finding) {
            $key = $finding->checkId.' '.$finding->subject;
            $packages[$key] ??= ['finding' => $finding, 'advisories' => []];
            $packages[$key]['advisories'][$finding->advisoryId ?? $finding->fixHint] = true;
        }

        $steps = [];

        foreach ($packages as $package) {
            $finding = $package['finding'];
            $advisoryCount = count($package['advisories']);
            $isNpm = $finding->checkId === 'npm-advisories';

            $steps[] = $this->plannedStep(
                $isNpm ? UpgradeStepKindEnum::NpmAdvisory : UpgradeStepKindEnum::ComposerAdvisory,
                [$this->packageFor($finding, $isNpm ? 'npm' : 'composer')],
                $advisoryCount === 1
                    ? trim($finding->fixHint)
                    : 'Update '.$finding->subject.' to ≥ fixed version (fixes '.$advisoryCount.' advisories).',
            );
        }

        return $steps;
    }

    /**
     * One step naming every package that blocks the next Laravel major.
     *
     * @param  list<FindingData>  $findings
     * @return list<PlannedStep>
     */
    private function blockerSteps(array $findings, ?int $nextLaravelMajor): array
    {
        if ($findings === []) {
            return [];
        }

        $packages = $this->packagesFor($findings, 'composer');
        $target = $nextLaravelMajor === null ? 'The next Laravel major' : 'Laravel '.$nextLaravelMajor;

        return [$this->plannedStep(
            UpgradeStepKindEnum::NextMajorBlocked,
            $packages,
            $target.' is blocked by '.$this->packageNames($packages).'; no release supports it yet: watch, replace or fork.',
            $nextLaravelMajor,
        )];
    }

    /**
     * One step per abandoned package, then one step for every stale package
     * that is not already abandoned.
     *
     * @param  list<FindingData>  $abandonedFindings
     * @param  list<FindingData>  $staleFindings
     * @return list<PlannedStep>
     */
    private function replacementSteps(array $abandonedFindings, array $staleFindings): array
    {
        $steps = [];
        $abandonedPackages = [];

        foreach ($abandonedFindings as $finding) {
            $abandonedPackages[] = $finding->subject;
            $steps[] = $this->plannedStep(
                UpgradeStepKindEnum::ComposerAbandoned,
                [$this->packageFor($finding, 'composer')],
                $finding->subject.' is abandoned. '.ucfirst(rtrim(trim($finding->fixHint), '.')).'.',
            );
        }

        $stalePackages = $this->packagesFor(array_values(array_filter(
            $staleFindings,
            static fn (FindingData $finding): bool => ! in_array($finding->subject, $abandonedPackages, true),
        )), 'composer');

        if ($stalePackages !== []) {
            $steps[] = $this->plannedStep(
                UpgradeStepKindEnum::ComposerStale,
                $stalePackages,
                'Consider maintained alternatives to stale or archived packages: '.$this->packageNames($stalePackages).'.',
            );
        }

        return $steps;
    }

    /**
     * One step naming the newest patch of every outdated package that has
     * no advisory finding of the same ecosystem; a vulnerable package already
     * has its own step.
     *
     * @param  list<FindingData>  $outdatedFindings
     * @param  list<FindingData>  $advisoryFindings
     * @return list<PlannedStep>
     */
    private function patchUpdateSteps(UpgradeStepKindEnum $kind, array $outdatedFindings, array $advisoryFindings): array
    {
        $isNpm = $kind === UpgradeStepKindEnum::NpmPatch;
        $vulnerablePackages = array_map(static fn (FindingData $finding): string => $finding->subject, $advisoryFindings);
        $packages = [];

        foreach ($outdatedFindings as $finding) {
            $latestPatch = $finding->details['latestPatch'] ?? null;

            if (! is_string($latestPatch) || in_array($finding->subject, $vulnerablePackages, true)) {
                continue;
            }

            $packages[] = $this->packageFor($finding, $isNpm ? 'npm' : 'composer', $latestPatch);
        }

        if ($packages === []) {
            return [];
        }

        $updates = array_map(
            static fn (UpgradeStepPackageData $package): string => $package->name.' '.$package->target,
            $packages,
        );
        $prefix = $isNpm ? 'Update npm packages to the latest patch: ' : 'Update to the latest patch: ';

        return [$this->plannedStep($kind, $packages, $prefix.implode(', ', $updates).'.')];
    }

    /**
     * One step when at least one license is denied (a `fail` row). It names
     * the denied packages and, in the same step, the strong copyleft ones
     * (the `warn` rows at medium); unknown licenses (low) are left out.
     * Without a denied license there is no step.
     *
     * @param  list<FindingData>  $findings
     * @return list<PlannedStep>
     */
    private function licenseReviewSteps(array $findings): array
    {
        $deniedPackages = $this->licensePackagesFor(array_values(array_filter(
            $findings,
            static fn (FindingData $finding): bool => $finding->status === FindingStatusEnum::Fail,
        )));

        if ($deniedPackages === []) {
            return [];
        }

        $copyleftPackages = $this->licensePackagesFor(array_values(array_filter(
            $findings,
            static fn (FindingData $finding): bool => $finding->status === FindingStatusEnum::Warn
                && $finding->severity === SeverityEnum::Medium,
        )));
        $message = 'Review dependency licenses: denied for '.$this->packageNames($deniedPackages);

        if ($copyleftPackages !== []) {
            $message .= '; strong copyleft for '.$this->packageNames($copyleftPackages);
        }

        return [$this->plannedStep(
            UpgradeStepKindEnum::LicenseReview,
            [...$deniedPackages, ...$copyleftPackages],
            $message.'. Replace the packages or clear the licenses after a legal review.',
        )];
    }

    /**
     * `dependency-licenses` rows carry their ecosystem in `details`; a row
     * without it is a Composer package, the check's first lockfile.
     *
     * @param  list<FindingData>  $findings
     * @return list<UpgradeStepPackageData>
     */
    private function licensePackagesFor(array $findings): array
    {
        $packages = [];

        foreach ($findings as $finding) {
            $packages[] = $this->packageFor($finding, ($finding->details['ecosystem'] ?? null) === 'npm' ? 'npm' : 'composer');
        }

        return $this->uniquePackages($packages);
    }

    /**
     * One package per finding subject, first occurrence kept.
     *
     * @param  list<FindingData>  $findings
     * @param  'composer'|'npm'  $ecosystem
     * @return list<UpgradeStepPackageData>
     */
    private function packagesFor(array $findings, string $ecosystem): array
    {
        return $this->uniquePackages(array_map(
            fn (FindingData $finding): UpgradeStepPackageData => $this->packageFor($finding, $ecosystem),
            $findings,
        ));
    }

    /**
     * The finding subject at its locked version (`details.lockedVersion`).
     *
     * @param  'composer'|'npm'  $ecosystem
     */
    private function packageFor(FindingData $finding, string $ecosystem, ?string $target = null): UpgradeStepPackageData
    {
        $lockedVersion = $finding->details['lockedVersion'] ?? null;

        return new UpgradeStepPackageData($finding->subject, $ecosystem, is_string($lockedVersion) ? $lockedVersion : null, $target);
    }

    /**
     * Drops a repeated package name, first occurrence kept, as the message
     * names each package once.
     *
     * @param  list<UpgradeStepPackageData>  $packages
     * @return list<UpgradeStepPackageData>
     */
    private function uniquePackages(array $packages): array
    {
        $packagesByName = [];

        foreach ($packages as $package) {
            $packagesByName[$package->name] ??= $package;
        }

        return array_values($packagesByName);
    }

    /**
     * @param  list<UpgradeStepPackageData>  $packages
     */
    private function packageNames(array $packages): string
    {
        return implode(', ', array_map(static fn (UpgradeStepPackageData $package): string => $package->name, $packages));
    }

    /**
     * @param  list<UpgradeStepPackageData>  $packages
     * @return PlannedStep
     */
    private function plannedStep(UpgradeStepKindEnum $kind, array $packages, string $message, ?int $targetMajor = null): array
    {
        return ['kind' => $kind, 'targetMajor' => $targetMajor, 'packages' => $packages, 'message' => $message];
    }
}
