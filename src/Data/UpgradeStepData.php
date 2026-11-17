<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

use Qiick\Excubia\Enums\UpgradeStepKindEnum;

/**
 * One numbered step of the upgrade plan.
 *
 * `kind`, `targetMajor` and `packages` are the structured form of the step
 * (#276); `message` is the sentence built from them, so text renderers and
 * structured consumers say the same thing. `targetMajor` is the Laravel major
 * of a `next-major-blocked` or `laravel-eol` step, null for every other kind.
 * The plan is informational. It is not a finding and it never changes the
 * exit code.
 */
final readonly class UpgradeStepData
{
    /**
     * @param  list<UpgradeStepPackageData>  $packages  in the order the message names them
     */
    public function __construct(
        public int $stepNumber,
        public string $message,
        public UpgradeStepKindEnum $kind,
        public ?int $targetMajor = null,
        public array $packages = [],
    ) {}
}
