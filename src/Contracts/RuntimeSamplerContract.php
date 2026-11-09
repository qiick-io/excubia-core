<?php

declare(strict_types=1);

namespace Qiick\Excubia\Contracts;

/**
 * Decides whether one runtime event at a fractional sample rate is recorded
 * (#230). `RuntimeSamplerService` draws from `mt_rand()`; tests bind a
 * deterministic sampler so sampled counts are exact instead of depending on
 * a global seed that any other code may advance.
 */
interface RuntimeSamplerContract
{
    /**
     * True when the event is taken. Only called with a rate strictly between
     * 0 and 1: rate 0 never records and rate 1 always does, without a draw.
     */
    public function isSampled(float $sampleRate): bool;
}
