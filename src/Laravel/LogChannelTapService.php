<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Log\Logger;
use Throwable;

/**
 * Laravel logging `tap` that lets the `logLevel` aggregate know the channel
 * name (#174). `RuntimeCollectorService` appends
 * `LogChannelTapService::class.':'.$channel` to the `tap` list of each
 * configured channel when `runtime.logLevels` is on, so Laravel calls this
 * once, lazily, when it resolves the channel. A channel that is never used
 * is never resolved.
 *
 * It must never throw: a failing tap makes Laravel replace the channel with
 * its emergency logger.
 */
final class LogChannelTapService
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param  string  ...$channelParts  the channel name, split by Laravel on commas
     */
    public function __invoke(Logger $logger, string ...$channelParts): void
    {
        try {
            if (! $this->container->bound(RuntimeCollectorService::class)) {
                return;
            }

            /** @var RuntimeCollectorService $collector */
            $collector = $this->container->make(RuntimeCollectorService::class);
            $collector->watchLogChannel($logger, implode(',', $channelParts));
        } catch (Throwable) {
            // Monitoring never reaches the host application.
        }
    }
}
