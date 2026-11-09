<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Contracts\RuntimeSamplerContract;

/**
 * The production sampler: one `mt_rand()` draw per event, taken when it
 * falls below the sample rate.
 */
final class RuntimeSamplerService implements RuntimeSamplerContract
{
    public function isSampled(float $sampleRate): bool
    {
        return mt_rand() / mt_getrandmax() < $sampleRate;
    }
}
