<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Closure;
use Qiick\Excubia\Data\CheckResultData;
use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\RuntimeTypeEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Throwable;

/**
 * The `health` runtime aggregates (#188): one per configured check, taken
 * by `excubia:push-runtime` at most once a minute, so excubia-app can alert
 * on an infrastructure failure within minutes instead of at the next daily
 * report push.
 *
 * Each aggregate carries the check id, its status (pass, warn, fail or
 * skipped), the most serious severity and the fingerprints
 * (FindingData::fingerprint()) of its warn and fail findings, and how many
 * there are. Never a message, subject, fix hint or detail value.
 *
 * Budgets: a check starts only while a whole check budget is still left of
 * the total budget, counted from `$startedAtMs` (the start of the run);
 * every later check is sent as `skipped`. A check that throws is `skipped`
 * too, and the next one still runs. Nothing here talks to the network.
 *
 * ponytail: PHP cannot interrupt a running probe, so a probe that hangs
 * past its check budget is bounded only by its own client timeout (3 s
 * connect for a database the probe opens); run the probes in a child
 * process with a hard deadline if a hung service ever stalls the push.
 */
final class HealthSampleService
{
    /**
     * The most fingerprints one aggregate carries; `findingCount` still
     * counts every warn and fail finding.
     */
    public const MAX_FINGERPRINTS = 20;

    /**
     * @param  Closure(): float  $clockMs  a monotonic clock in milliseconds
     */
    public function __construct(
        private readonly int $checkBudgetMs,
        private readonly int $totalBudgetMs,
        private readonly Closure $clockMs,
    ) {}

    /**
     * One aggregate per check id, in the given order, for the minute that
     * holds `$timestamp`.
     *
     * @param  list<string>  $checkIds
     * @param  Closure(string): CheckResultData  $runCheck  probes and runs one check
     * @return list<array<string, mixed>>
     */
    public function aggregates(array $checkIds, Closure $runCheck, float $startedAtMs, string $server, float $sampleRate, int $timestamp): array
    {
        $aggregates = [];

        foreach ($checkIds as $checkId) {
            $result = null;

            if (($this->clockMs)() - $startedAtMs + $this->checkBudgetMs <= $this->totalBudgetMs) {
                try {
                    $result = $runCheck($checkId);
                } catch (Throwable) {
                    // A failing probe never stops the push: the check is sent as skipped.
                }
            }

            $aggregates[] = self::aggregate($checkId, $result, $server, $sampleRate, $timestamp);
        }

        return $aggregates;
    }

    /**
     * The aggregate of one check; a null result (not run, or threw) is
     * `skipped` with no findings.
     *
     * @return array<string, mixed>
     */
    public static function aggregate(string $checkId, ?CheckResultData $result, string $server, float $sampleRate, int $timestamp): array
    {
        $severity = SeverityEnum::Info;
        $fingerprints = [];

        foreach ($result === null ? [] : $result->findings as $finding) {
            if ($finding->status !== FindingStatusEnum::Warn && $finding->status !== FindingStatusEnum::Fail) {
                continue;
            }

            $fingerprints[] = $finding->fingerprint();

            if ($finding->severity->weight() > $severity->weight()) {
                $severity = $finding->severity;
            }
        }

        $status = $result === null ? FindingStatusEnum::Skipped : $result->status;
        $uniqueFingerprints = array_values(array_unique($fingerprints));
        sort($uniqueFingerprints, SORT_STRING);

        return [
            'type' => RuntimeTypeEnum::Health->value,
            'bucket' => gmdate('Y-m-d\TH:i:00\Z', $timestamp),
            'sampleRate' => $sampleRate,
            'server' => $server,
            'checkId' => $checkId,
            'count' => 1,
            'errorCount' => $status === FindingStatusEnum::Fail ? 1 : 0,
            'status' => $status->value,
            'severity' => $severity->value,
            'findingCount' => count($fingerprints),
            'fingerprints' => array_slice($uniqueFingerprints, 0, self::MAX_FINGERPRINTS),
        ];
    }
}
