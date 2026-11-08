<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Enums\FindingStatusEnum;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * Local, size-capped buffer of runtime aggregates (`docs/runtime-schema.json`)
 * between the Laravel collector and `excubia:push-runtime`. Exception capture
 * uses a second instance on its own directory (`storage/excubia/exceptions/`)
 * with the same files, lock, cap and batch ids, and reads it with
 * `readEntries()`.
 *
 * Layout of the directory (`storage/excubia/runtime/` in a Laravel app):
 *
 * - `buffer.ndjson`: one aggregate per line, appended at the end of each
 *   request, job or scheduled task;
 * - `batch-<sequence>-<batchId>.ndjson`: the buffer as it was when a push
 *   claimed it. The batch id lives in the file name, so every retry of a
 *   batch that the app did not acknowledge sends the same `batchId`;
 * - `.lock`: every append, claim and trim holds an exclusive `flock` on it,
 *   so a claim never loses a line that a request is writing.
 *
 * When the files together grow past the cap, whole lines are dropped from
 * the oldest file first (the oldest batch, then the buffer). The cap is
 * checked on every append. Nothing in this class talks to the network.
 *
 * ponytail: one flock per append serialises concurrent writers on one host;
 * enough for PHP-FPM pools. A shared buffer across hosts would need a store
 * such as Redis.
 */
final class RuntimeBufferService
{
    public const BUFFER_FILE = 'buffer.ndjson';

    private const LOCK_FILE = '.lock';

    private const BATCH_PATTERN = '/^batch-(\d{20})-([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\.ndjson$/';

    /**
     * Metric fields that add up when two aggregates merge: integer counters
     * and decimal totals. A type carries only the ones it needs (#132,
     * `totalRepeats` for `duplicateQuery`, #148, and `failoverCount` for
     * `ai`, #198, the worker counts of `octane`, #213, and the connection,
     * message and channel counts of `reverb`, #215, and the handled and
     * payment signal counts of `billingWebhook`, #217).
     */
    private const COUNTER_FIELDS = ['count', 'errorCount', 'hits', 'misses', 'writes', 'deletes', 'totalRepeats', 'failoverCount', 'workerStarts', 'workerStops', 'requests', 'tasks', 'ticks', 'totalRequestsAtStop', 'connectionsOpened', 'connectionsPruned', 'connectionsRejected', 'messagesReceived', 'messagesSent', 'publicChannelsCreated', 'privateChannelsCreated', 'presenceChannelsCreated', 'publicChannelsRemoved', 'privateChannelsRemoved', 'presenceChannelsRemoved', 'handledCount', 'paymentFailedCount', 'paymentActionRequiredCount', 'subscriptionCanceledCount'];

    private const TOTAL_FIELDS = ['totalMs', 'totalMb'];

    /**
     * Metric fields that keep the larger value when two aggregates merge
     * (`maxGrowthMb` of `octane`, #213).
     */
    private const MAXIMUM_FIELDS = ['maxMs', 'maxMb', 'maxGrowthMb'];

    /**
     * Integer metric fields that keep the larger value when two aggregates
     * merge, and stay integers (`maxRepeats` of `duplicateQuery`, #148,
     * `findingCount` of `health`, #188, `maxRequestsAtStop` of `octane`, #213,
     * `maxConnections` of `reverb`, #215).
     */
    private const MAXIMUM_COUNTER_FIELDS = ['maxRepeats', 'findingCount', 'maxRequestsAtStop', 'maxConnections'];

    /**
     * Outcome fields of the `health` type (#188). Two samples of one check
     * in one minute keep the worst `status` (FindingStatusEnum::sampleRank():
     * fail, then warn, then pass, then skipped), the most serious `severity`,
     * and the union of `fingerprints`, sorted and cut to
     * HealthSampleService::MAX_FINGERPRINTS.
     */
    private const STATUS_FIELD = 'status';

    private const SEVERITY_FIELD = 'severity';

    private const FINGERPRINTS_FIELD = 'fingerprints';

    /**
     * Gauges of the `server` type (#149): a reading, not a count, and null
     * when the platform does not expose it. Two samples of one server in one
     * minute keep the larger reading of each gauge (null only when both are
     * null), so the merge does not depend on order and an alert never
     * misses a peak.
     */
    public const GAUGE_FIELDS = ['loadAverage1', 'loadAverage5', 'loadAverage15', 'cpuCores', 'cpuLimit', 'memoryUsedMb', 'memoryTotalMb', 'diskUsedGb', 'diskTotalGb'];

    /**
     * Usage of the `ai` type (#198): token, audio second and search unit
     * counts, null when the provider does not report them. They add up when
     * two aggregates merge, and the sum is null only when both sides are
     * null (a missing value counts as null), so a count no provider reported
     * never turns into 0.
     */
    public const NULLABLE_SUM_FIELDS = [
        'inputTokens',
        'outputTokens',
        'cacheReadInputTokens',
        'cacheWriteInputTokens',
        'reasoningTokens',
        'imageInputTokens',
        'imageOutputTokens',
        'audioSeconds',
        'searchUnits',
    ];

    /**
     * Boolean metric fields: true when either side of a merge is true
     * (`isContainer` of `server`, #173). A missing value counts as false.
     */
    private const FLAG_FIELDS = ['isContainer'];

    /**
     * Metric fields that hold one number each.
     */
    private const NUMBER_FIELDS = [...self::COUNTER_FIELDS, ...self::TOTAL_FIELDS, ...self::MAXIMUM_FIELDS, ...self::MAXIMUM_COUNTER_FIELDS];

    /**
     * Latency histogram of the duration types (#147): a list of counts, one
     * per bucket of `RuntimeTypeEnum::HISTOGRAM_BOUNDS_MS` plus overflow.
     */
    private const HISTOGRAM_FIELD = 'histogram';

    /**
     * Every metric field. Every other field is a dimension and part of the
     * merge key.
     */
    private const METRIC_FIELDS = [...self::NUMBER_FIELDS, ...self::GAUGE_FIELDS, ...self::NULLABLE_SUM_FIELDS, ...self::FLAG_FIELDS, self::HISTOGRAM_FIELD, self::STATUS_FIELD, self::SEVERITY_FIELD, self::FINGERPRINTS_FIELD];

    public function __construct(
        private readonly string $directory,
        private readonly int $maxBytes,
    ) {}

    /**
     * Append aggregates to the buffer, then drop the oldest lines while the
     * files are over the cap.
     *
     * @param  list<array<string, mixed>>  $aggregates
     *
     * @throws InvalidConfigurationException when the directory cannot be created or written
     */
    public function append(array $aggregates): void
    {
        if ($aggregates === []) {
            return;
        }

        $lines = '';

        foreach ($aggregates as $aggregate) {
            $lines .= json_encode($aggregate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        }

        $this->locked(function () use ($lines): void {
            if (file_put_contents($this->path(self::BUFFER_FILE), $lines, FILE_APPEND) === false) {
                throw new InvalidConfigurationException('The runtime buffer could not be written.');
            }

            $this->trimToCap();
        });
    }

    /**
     * Move the current buffer into a new batch with a fresh batch id and
     * return every batch waiting to be sent, oldest first. Batches a
     * previous run could not send are returned again with their own id.
     *
     * @return list<array{batchId: string, path: string}>
     *
     * @throws InvalidConfigurationException
     */
    public function claimBatches(): array
    {
        return $this->locked(function (): array {
            $bufferPath = $this->path(self::BUFFER_FILE);

            if (is_file($bufferPath) && filesize($bufferPath) > 0) {
                $sequence = str_pad((string) (int) (microtime(true) * 1_000_000), 20, '0', STR_PAD_LEFT);

                if (! rename($bufferPath, $this->path('batch-'.$sequence.'-'.ReportPushService::newBatchId().'.ndjson'))) {
                    throw new InvalidConfigurationException('The runtime buffer could not be claimed.');
                }
            }

            $batches = [];

            foreach ($this->batchFiles() as $name) {
                if (preg_match(self::BATCH_PATTERN, $name, $matches) === 1) {
                    $batches[] = ['batchId' => $matches[2], 'path' => $this->path($name)];
                }
            }

            return $batches;
        });
    }

    /**
     * The aggregates of one batch file, merged by dimensions. Lines that are
     * not aggregates (a torn write, a hand edit) are skipped.
     *
     * @return list<array<string, mixed>>
     */
    public function read(string $batchPath): array
    {
        return self::merge(array_values(array_filter($this->readEntries($batchPath), self::isAggregate(...))));
    }

    /**
     * Every JSON object line of one batch file, unmerged. Exception capture
     * stores its groups in a buffer of its own and merges them itself.
     * Lines that are not JSON objects are skipped.
     *
     * @return list<array<string, mixed>>
     */
    public function readEntries(string $batchPath): array
    {
        $contents = is_file($batchPath) ? file_get_contents($batchPath) : false;

        if ($contents === false) {
            return [];
        }

        $entries = [];

        foreach (explode("\n", $contents) as $line) {
            $entry = $line === '' ? null : json_decode($line, true);

            if (is_array($entry) && ! array_is_list($entry)) {
                /** @var array<string, mixed> $entry */
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Remove a batch the app acknowledged. A batch the cap already dropped
     * is not an error.
     */
    public function delete(string $batchPath): void
    {
        $this->locked(function () use ($batchPath): void {
            if (is_file($batchPath)) {
                unlink($batchPath);
            }
        });
    }

    /**
     * Merge aggregates that share every dimension (type, bucket, sample rate
     * and the type's keys): counts and totals add up, maxima take the larger,
     * histograms add up element by element, gauges keep the larger reading,
     * usage counts add up (null only when both are null), and a `health` sample keeps the worst status (see `combine()`).
     *
     * @param  list<array<string, mixed>>  $aggregates
     * @return list<array<string, mixed>>
     */
    public static function merge(array $aggregates): array
    {
        $merged = [];

        foreach ($aggregates as $aggregate) {
            $key = self::mergeKey($aggregate);

            if (! isset($merged[$key])) {
                $merged[$key] = $aggregate;

                continue;
            }

            $merged[$key] = self::combine($merged[$key], $aggregate);
        }

        return array_values($merged);
    }

    /**
     * The dimensions of an aggregate as one string.
     *
     * @param  array<string, mixed>  $aggregate
     */
    public static function mergeKey(array $aggregate): string
    {
        $dimensions = array_diff_key($aggregate, array_flip(self::METRIC_FIELDS));
        ksort($dimensions);

        return json_encode($dimensions, JSON_THROW_ON_ERROR);
    }

    /**
     * Add one aggregate's metrics to another with the same dimensions.
     * Only the metric fields present in either side are written.
     *
     * Histograms add up element by element. When only one side has a
     * histogram, or the two have a different number of buckets (written by
     * versions with other bounds), the merged aggregate has none: its counts
     * would no longer add up to `count`, and the histogram is optional.
     *
     * @param  array<string, mixed>  $into
     * @param  array<string, mixed>  $from
     * @return array<string, mixed>
     */
    public static function combine(array $into, array $from): array
    {
        foreach (self::COUNTER_FIELDS as $field) {
            if (array_key_exists($field, $into) || array_key_exists($field, $from)) {
                $into[$field] = self::integerValue($into[$field] ?? 0) + self::integerValue($from[$field] ?? 0);
            }
        }

        foreach (self::TOTAL_FIELDS as $field) {
            if (array_key_exists($field, $into) || array_key_exists($field, $from)) {
                $into[$field] = round(self::numberValue($into[$field] ?? 0) + self::numberValue($from[$field] ?? 0), 3);
            }
        }

        foreach (self::MAXIMUM_FIELDS as $field) {
            if (array_key_exists($field, $into) || array_key_exists($field, $from)) {
                $into[$field] = max(self::numberValue($into[$field] ?? 0), self::numberValue($from[$field] ?? 0));
            }
        }

        foreach (self::MAXIMUM_COUNTER_FIELDS as $field) {
            if (array_key_exists($field, $into) || array_key_exists($field, $from)) {
                $into[$field] = max(self::integerValue($into[$field] ?? 0), self::integerValue($from[$field] ?? 0));
            }
        }

        foreach (self::GAUGE_FIELDS as $field) {
            if (array_key_exists($field, $into) || array_key_exists($field, $from)) {
                $into[$field] = self::largerGauge($into[$field] ?? null, $from[$field] ?? null);
            }
        }

        foreach (self::NULLABLE_SUM_FIELDS as $field) {
            if (array_key_exists($field, $into) || array_key_exists($field, $from)) {
                $into[$field] = self::nullableSum($into[$field] ?? null, $from[$field] ?? null);
            }
        }

        foreach (self::FLAG_FIELDS as $field) {
            if (array_key_exists($field, $into) || array_key_exists($field, $from)) {
                $into[$field] = ($into[$field] ?? false) === true || ($from[$field] ?? false) === true;
            }
        }

        if (is_string($into[self::STATUS_FIELD] ?? null) && is_string($from[self::STATUS_FIELD] ?? null)) {
            $into[self::STATUS_FIELD] = self::worse(FindingStatusEnum::from($into[self::STATUS_FIELD]), FindingStatusEnum::from($from[self::STATUS_FIELD]))->value;
        }

        if (is_string($into[self::SEVERITY_FIELD] ?? null) && is_string($from[self::SEVERITY_FIELD] ?? null)
            && SeverityEnum::from($from[self::SEVERITY_FIELD])->weight() > SeverityEnum::from($into[self::SEVERITY_FIELD])->weight()) {
            $into[self::SEVERITY_FIELD] = $from[self::SEVERITY_FIELD];
        }

        if (self::isStringList($into[self::FINGERPRINTS_FIELD] ?? null) && self::isStringList($from[self::FINGERPRINTS_FIELD] ?? null)) {
            $fingerprints = array_values(array_unique([...$into[self::FINGERPRINTS_FIELD], ...$from[self::FINGERPRINTS_FIELD]]));
            sort($fingerprints, SORT_STRING);
            $into[self::FINGERPRINTS_FIELD] = array_slice($fingerprints, 0, HealthSampleService::MAX_FINGERPRINTS);
        }

        if (array_key_exists(self::HISTOGRAM_FIELD, $into) || array_key_exists(self::HISTOGRAM_FIELD, $from)) {
            $histogram = self::sumHistograms($into[self::HISTOGRAM_FIELD] ?? null, $from[self::HISTOGRAM_FIELD] ?? null);

            if ($histogram === null) {
                unset($into[self::HISTOGRAM_FIELD]);
            } else {
                $into[self::HISTOGRAM_FIELD] = $histogram;
            }
        }

        return $into;
    }

    private static function worse(FindingStatusEnum $first, FindingStatusEnum $second): FindingStatusEnum
    {
        return $second->sampleRank() > $first->sampleRank() ? $second : $first;
    }

    /**
     * The larger of two gauge readings; a null reading loses to a number.
     */
    private static function largerGauge(mixed $first, mixed $second): int|float|null
    {
        $readings = array_filter([$first, $second], static fn (mixed $reading): bool => is_int($reading) || is_float($reading));

        return $readings === [] ? null : max($readings);
    }

    /**
     * The sum of two usage counts (#198), null when neither is a number.
     * Integers stay integers; a decimal sum keeps 3 decimals.
     */
    private static function nullableSum(mixed $first, mixed $second): int|float|null
    {
        $counts = array_filter([$first, $second], static fn (mixed $count): bool => is_int($count) || is_float($count));

        if ($counts === []) {
            return null;
        }

        $sum = array_sum($counts);

        return is_float($sum) ? round($sum, 3) : $sum;
    }

    /**
     * Element-by-element sum of two histograms, or null when either is
     * missing or they differ in length.
     *
     * @return list<int>|null
     */
    private static function sumHistograms(mixed $first, mixed $second): ?array
    {
        if (! self::isHistogram($first) || ! self::isHistogram($second) || count($first) !== count($second)) {
            return null;
        }

        $sum = [];

        foreach ($first as $index => $bucketCount) {
            $sum[] = $bucketCount + $second[$index];
        }

        return $sum;
    }

    /**
     * @phpstan-assert-if-true list<int> $value
     */
    private static function isHistogram(mixed $value): bool
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $bucketCount) {
            if (! is_int($bucketCount) || $bucketCount < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every aggregate has a type, a bucket, `count` and `errorCount`; any
     * other metric field it carries is a number, a gauge or a usage count
     * is a number or null, a flag is a boolean, a histogram is a list of non-negative counts,
     * and a `health` status, severity and fingerprint list are known values.
     *
     * @param  array<mixed>  $aggregate
     */
    private static function isAggregate(array $aggregate): bool
    {
        if (! is_string($aggregate['type'] ?? null)
            || ! is_string($aggregate['bucket'] ?? null)
            || ! is_int($aggregate['count'] ?? null)
            || ! is_int($aggregate['errorCount'] ?? null)) {
            return false;
        }

        foreach (self::NUMBER_FIELDS as $field) {
            if (array_key_exists($field, $aggregate) && ! is_int($aggregate[$field]) && ! is_float($aggregate[$field])) {
                return false;
            }
        }

        foreach ([...self::GAUGE_FIELDS, ...self::NULLABLE_SUM_FIELDS] as $field) {
            if (array_key_exists($field, $aggregate) && $aggregate[$field] !== null && ! is_int($aggregate[$field]) && ! is_float($aggregate[$field])) {
                return false;
            }
        }

        foreach (self::FLAG_FIELDS as $field) {
            if (array_key_exists($field, $aggregate) && ! is_bool($aggregate[$field])) {
                return false;
            }
        }

        if (array_key_exists(self::STATUS_FIELD, $aggregate) && (! is_string($aggregate[self::STATUS_FIELD]) || FindingStatusEnum::tryFrom($aggregate[self::STATUS_FIELD]) === null)) {
            return false;
        }

        if (array_key_exists(self::SEVERITY_FIELD, $aggregate) && (! is_string($aggregate[self::SEVERITY_FIELD]) || SeverityEnum::tryFrom($aggregate[self::SEVERITY_FIELD]) === null)) {
            return false;
        }

        if (array_key_exists(self::FINGERPRINTS_FIELD, $aggregate) && ! self::isStringList($aggregate[self::FINGERPRINTS_FIELD])) {
            return false;
        }

        return ! array_key_exists(self::HISTOGRAM_FIELD, $aggregate) || self::isHistogram($aggregate[self::HISTOGRAM_FIELD]);
    }

    /**
     * @phpstan-assert-if-true list<string> $value
     */
    private static function isStringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && array_filter($value, is_string(...)) === $value;
    }

    private static function integerValue(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    private static function numberValue(mixed $value): float
    {
        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }

    /**
     * Drop whole lines from the oldest file until all files fit the cap.
     * A file that would end up empty is removed.
     */
    private function trimToCap(): void
    {
        clearstatcache();
        $files = [...$this->batchFiles(), self::BUFFER_FILE];
        $sizes = [];

        foreach ($files as $name) {
            $size = is_file($this->path($name)) ? filesize($this->path($name)) : false;
            $sizes[$name] = $size === false ? 0 : $size;
        }

        $excess = array_sum($sizes) - $this->maxBytes;

        foreach ($files as $name) {
            if ($excess <= 0) {
                return;
            }

            $path = $this->path($name);

            if ($sizes[$name] <= $excess) {
                $excess -= $sizes[$name];

                if (is_file($path)) {
                    unlink($path);
                }

                continue;
            }

            $excess -= $this->dropLeadingBytes($path, $excess);
        }
    }

    /**
     * Rewrite a file without its first lines, at least `$bytes` of them.
     * Written to a temporary file and renamed, so no reader sees half a file.
     *
     * @return int the number of bytes removed
     */
    private function dropLeadingBytes(string $path, int $bytes): int
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return 0;
        }

        $cut = strpos($contents, "\n", $bytes - 1);
        $kept = $cut === false ? '' : substr($contents, $cut + 1);
        $temporaryPath = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($temporaryPath, $kept) === false || ! rename($temporaryPath, $path)) {
            return 0;
        }

        return strlen($contents) - strlen($kept);
    }

    /**
     * Batch file names, oldest first.
     *
     * @return list<string>
     */
    private function batchFiles(): array
    {
        $names = scandir($this->directory);

        if ($names === false) {
            return [];
        }

        $batches = array_values(array_filter($names, static fn (string $name): bool => preg_match(self::BATCH_PATTERN, $name) === 1));
        sort($batches, SORT_STRING);

        return $batches;
    }

    /**
     * Run a callback under the exclusive lock of the buffer directory.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     *
     * @throws InvalidConfigurationException
     */
    private function locked(callable $callback): mixed
    {
        if (! is_dir($this->directory) && (! $this->isCreatable() || ! mkdir($this->directory, 0775, true)) && ! is_dir($this->directory)) {
            throw new InvalidConfigurationException('The runtime buffer directory could not be created.');
        }

        $lock = fopen($this->path(self::LOCK_FILE), 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new InvalidConfigurationException('The runtime buffer could not be locked.');
        }

        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * True when the nearest existing ancestor of the directory is a writable
     * directory, so `mkdir` can succeed without raising a warning.
     */
    private function isCreatable(): bool
    {
        $ancestor = $this->directory;

        while (! file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }

        return is_dir($ancestor) && is_writable($ancestor);
    }

    private function path(string $name): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$name;
    }
}
