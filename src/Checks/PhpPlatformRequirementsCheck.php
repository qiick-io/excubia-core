<?php

declare(strict_types=1);

namespace Qiick\Excubia\Checks;

use Qiick\Excubia\Contracts\HealthCheckContract;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Data\ExcubiaConfigurationData;
use Qiick\Excubia\Data\FindingData;
use Qiick\Excubia\Data\PlatformRequirementData;
use Qiick\Excubia\Data\ProjectSnapshotData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Services\ComposerConstraintService;

/**
 * Fails at high for each `php` or `ext-*` requirement of `composer.lock`
 * (the `require` of every locked package plus the root `platform` keys)
 * that the running PHP does not meet, like `composer check-platform-reqs`.
 *
 * Under Artisan the running PHP is the app's. The standalone CLI may run on
 * another PHP, so its findings say so (`isStandaloneCli`). An extension a
 * locked package provides or replaces (a polyfill) counts as present.
 * `platform-overrides` (`config.platform`) are not trusted: they hide a
 * missing extension from Composer, so the fix hint names them.
 *
 * ponytail: extension version constraints are not compared, only presence;
 * compare `phpversion($extension)` here if a project ever needs it.
 */
final class PhpPlatformRequirementsCheck implements HealthCheckContract
{
    private const EVIDENCE_URL = 'https://getcomposer.org/doc/03-cli.md#check-platform-reqs';

    /**
     * @param  string|null  $phpVersion  running PHP version; null reads `PHP_*_VERSION`
     * @param  list<string>|null  $loadedExtensionNames  loaded extensions; null reads `get_loaded_extensions()`
     */
    public function __construct(
        private readonly ComposerConstraintService $composerConstraint = new ComposerConstraintService,
        private readonly ?string $phpVersion = null,
        private readonly ?array $loadedExtensionNames = null,
    ) {}

    public function id(): string
    {
        return 'php-platform-requirements';
    }

    public function name(): string
    {
        return 'Platform requirements';
    }

    public function run(ProjectSnapshotData $projectSnapshot, ExcubiaConfigurationData $configuration): CheckResultData
    {
        $phpVersion = $this->phpVersion ?? PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION;
        $isStandaloneCli = $projectSnapshot->laravelPosture === null;
        $loaded = [];

        foreach ($this->loadedExtensionNames ?? get_loaded_extensions() as $extension) {
            $loaded['ext-'.strtolower(str_replace(' ', '-', $extension))] = true;
        }

        $provided = array_fill_keys($projectSnapshot->providedPlatformNames, true);
        $unmet = [];

        foreach ($projectSnapshot->platformRequirements as $requirement) {
            if (isset($provided[$requirement->name]) || ! $this->isUnmet($requirement, $phpVersion, $loaded)) {
                continue;
            }

            $unmet[$requirement->name][$requirement->requiredBy] = $requirement->constraint;
        }

        $findings = [];

        foreach ($unmet as $name => $constraintsByPackage) {
            $findings[] = $this->finding(
                (string) $name,
                $constraintsByPackage,
                $phpVersion,
                $isStandaloneCli,
                in_array($name, $projectSnapshot->platformOverrideNames, true),
            );
        }

        return CheckResultData::fromFindings($this->id(), $this->name(), $findings);
    }

    /**
     * @param  array<string, true>  $loaded
     */
    private function isUnmet(PlatformRequirementData $requirement, string $phpVersion, array $loaded): bool
    {
        if ($requirement->name === 'php') {
            return ! $this->composerConstraint->isSatisfiedBy($phpVersion, $requirement->constraint);
        }

        return ! isset($loaded[$requirement->name]);
    }

    /**
     * @param  array<string, string>  $constraintsByPackage  constraint per requiring package
     */
    private function finding(string $name, array $constraintsByPackage, string $phpVersion, bool $isStandaloneCli, bool $isOverridden): FindingData
    {
        $requiredBy = array_map('strval', array_keys($constraintsByPackage));

        if ($name === 'php') {
            $parts = [];

            foreach ($constraintsByPackage as $package => $constraint) {
                $parts[] = $package.' ('.$constraint.')';
            }

            $message = 'PHP '.$phpVersion.' does not satisfy the PHP requirement of '.implode(', ', $parts).'.';
            $fixHint = 'Run the app on a PHP version every locked package allows, or update the packages that exclude this version.';
        } else {
            $extension = substr($name, strlen('ext-'));
            $message = 'The '.$extension.' extension is not loaded; required by '.implode(', ', $requiredBy).'.';
            $fixHint = 'Install and enable the PHP extension '.$extension.' (php -m lists the loaded ones).';
        }

        if ($isStandaloneCli) {
            $message .= ' Checked against the PHP running vendor/bin/excubia ('.$phpVersion.'), which can differ from the app\'s PHP; php artisan excubia:check checks the app\'s.';
        }

        if ($isOverridden) {
            $fixHint .= ' composer.json config.platform declares '.$name.', so Composer did not catch it.';
        }

        return new FindingData(
            checkId: $this->id(),
            severity: SeverityEnum::High,
            status: FindingStatusEnum::Fail,
            subject: $name,
            message: $message,
            fixHint: $fixHint,
            evidenceUrl: self::EVIDENCE_URL,
            details: [
                'requirement' => $name,
                'requiredBy' => $requiredBy,
                'checkedPhpVersion' => $phpVersion,
                'isStandaloneCli' => $isStandaloneCli,
            ],
        );
    }
}
