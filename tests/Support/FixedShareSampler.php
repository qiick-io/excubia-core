<?php

declare(strict_types=1);

namespace Qiick\Excubia\Tests\Support;

use Qiick\Excubia\Contracts\RuntimeSamplerContract;

/**
 * Deterministic stand-in for `RuntimeSamplerService` (#230): takes exactly
 * the configured share of the events it is asked about, spread evenly (at
 * 0.5 every second one), so tests can assert exact sampled counts.
 */
final class FixedShareSampler implements RuntimeSamplerContract
{
    public int $drawCount = 0;

    public function isSampled(float $sampleRate): bool
    {
        $previousTaken = (int) floor($this->drawCount * $sampleRate);
        $this->drawCount++;

        return (int) floor($this->drawCount * $sampleRate) > $previousTaken;
    }
}
