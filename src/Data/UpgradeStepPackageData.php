<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

/**
 * One package an upgrade plan step names (#276).
 *
 * `installed` is the locked version (the running or required PHP version for
 * `php`), null when the finding does not carry it. `target` is the version
 * the step moves to, set only where the plan knows one: the latest patch of
 * the `composer-patch` and `npm-patch` steps.
 */
final readonly class UpgradeStepPackageData
{
    /**
     * @param  'composer'|'npm'  $ecosystem
     */
    public function __construct(
        public string $name,
        public string $ecosystem,
        public ?string $installed,
        public ?string $target,
    ) {}
}
