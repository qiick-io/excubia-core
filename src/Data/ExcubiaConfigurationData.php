<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

use DateTimeImmutable;
use DateTimeZone;
use Qiick\Excubia\Enums\SeverityEnum;
use Qiick\Excubia\Exceptions\InvalidConfigurationException;

/**
 * Knobs the checks and the cache read. The JSON loader fills this object.
 *
 * Defaults match the spec: fail on high, include dev dependencies, stale
 * after 24 months, warn 180 days before end of life, node check enabled,
 * cache TTL 24 hours. A null `failOnSeverity` is `--fail-on=none`.
 * A null `cacheDirectory` means the cache service chooses its own directory.
 * `appUrl` and `appToken` come from the `app` key and are read only by
 * `excubia push`; the token is never printed, cached or written to a report.
 * `isStrict` is the `strict` key: data-source failures exit 3 (`--strict`).
 * `isHealthEndpointEnabled` and `healthEndpointPath` are the `healthEndpoint`
 * keys read by the Laravel provider (`docs/health-endpoint.md`).
 * The posture thresholds default to a scheduler heartbeat older than 10
 * minutes and failed jobs older than 24 hours (high instead of medium).
 * The infrastructure thresholds default to a disk warning at 80 % used and
 * a failure at 90 %, and a queue backlog warning when the oldest pending job
 * waits 15 minutes or a queue holds 1000 pending jobs.
 * `databaseConnectionNames` are probed on top of the default connection;
 * `queueBacklogQueueNames` are read on top of the default queue (Redis only,
 * the database driver reads every queue in its table).
 * `runtime` is the opt-in runtime monitoring key (`RuntimeConfigurationData`),
 * `exceptions` the separate opt-in exception capture key (`ExceptionConfigurationData`).
 * `skippedPackagePatterns` is `privacy.skipPackages`: globs (`*`, `?`) of
 * package names that are never sent to a public data source, on top of the
 * packages the lockfile already marks private.
 */
final readonly class ExcubiaConfigurationData
{
    public const DEFAULT_HEALTH_ENDPOINT_PATH = 'excubia/health';

    private const PACKAGE_PATTERN_SYNTAX = '#^[A-Za-z0-9@/._~*?-]+$#';

    /**
     * @param  list<array{
     *     checkId: string,
     *     subject: string,
     *     advisoryId: string|null,
     *     reason: string,
     *     until: string|null
     * }>  $ignoreRules
     * @param  list<string>  $skippedPackagePatterns
     * @param  list<string>  $databaseConnectionNames
     * @param  list<string>  $queueBacklogQueueNames
     */
    public function __construct(
        public ?SeverityEnum $failOnSeverity,
        public bool $isDevelopmentDependencyIncluded,
        public int $staleAfterMonths,
        public int $phpWarnDaysBeforeEndOfLife,
        public int $laravelWarnDaysBeforeEndOfLife,
        public bool $isNodeEndOfLifeCheckEnabled,
        public array $ignoreRules,
        public ?string $cacheDirectory,
        public int $cacheTtlHours,
        public ?string $appUrl = null,
        #[\SensitiveParameter]
        public ?string $appToken = null,
        public bool $isStrict = false,
        public bool $isHealthEndpointEnabled = false,
        public string $healthEndpointPath = self::DEFAULT_HEALTH_ENDPOINT_PATH,
        public int $schedulerHeartbeatStaleAfterMinutes = 10,
        public int $failedJobHighSeverityAfterHours = 24,
        public RuntimeConfigurationData $runtime = new RuntimeConfigurationData,
        public ExceptionConfigurationData $exceptions = new ExceptionConfigurationData,
        public array $skippedPackagePatterns = [],
        public array $databaseConnectionNames = [],
        public int $diskSpaceWarnAtPercentUsed = 80,
        public int $diskSpaceFailAtPercentUsed = 90,
        public int $queueBacklogMaxOldestAgeMinutes = 15,
        public int $queueBacklogMaxPendingJobs = 1000,
        public array $queueBacklogQueueNames = [],
    ) {
        if ($this->staleAfterMonths < 1) {
            throw new InvalidConfigurationException('staleAfterMonths must be at least 1.');
        }

        if ($this->phpWarnDaysBeforeEndOfLife < 0 || $this->laravelWarnDaysBeforeEndOfLife < 0) {
            throw new InvalidConfigurationException('warnDaysBeforeEol must be zero or greater.');
        }

        if ($this->schedulerHeartbeatStaleAfterMinutes < 1) {
            throw new InvalidConfigurationException('staleAfterMinutes must be at least 1.');
        }

        if ($this->failedJobHighSeverityAfterHours < 1) {
            throw new InvalidConfigurationException('highSeverityAfterHours must be at least 1.');
        }

        if ($this->diskSpaceWarnAtPercentUsed < 1 || $this->diskSpaceFailAtPercentUsed > 100 || $this->diskSpaceWarnAtPercentUsed > $this->diskSpaceFailAtPercentUsed) {
            throw new InvalidConfigurationException('laravel-disk-space needs 1 <= warnAtPercentUsed <= failAtPercentUsed <= 100.');
        }

        if ($this->queueBacklogMaxOldestAgeMinutes < 1 || $this->queueBacklogMaxPendingJobs < 1) {
            throw new InvalidConfigurationException('maxOldestAgeMinutes and maxPendingJobs must be at least 1.');
        }

        foreach ([...$this->databaseConnectionNames, ...$this->queueBacklogQueueNames] as $name) {
            if ($name === '') {
                throw new InvalidConfigurationException('checks.laravel-database.connections and checks.laravel-queue-backlog.queues entries must be non-empty names.');
            }
        }

        if ($this->cacheTtlHours < 0) {
            throw new InvalidConfigurationException('cache ttlHours must be zero or greater.');
        }

        if (preg_match('#^[A-Za-z0-9._~-]+(/[A-Za-z0-9._~-]+)*$#', $this->healthEndpointPath) !== 1) {
            throw new InvalidConfigurationException('healthEndpoint.path must be a relative URL path such as excubia/health.');
        }

        foreach ($this->ignoreRules as $ignoreRule) {
            $this->assertIgnoreRule($ignoreRule);
        }

        foreach ($this->skippedPackagePatterns as $index => $pattern) {
            if (preg_match(self::PACKAGE_PATTERN_SYNTAX, $pattern) !== 1) {
                throw new InvalidConfigurationException('privacy.skipPackages['.$index.'] must be a package name glob: letters, digits, @ / . _ ~ - and the wildcards * and ?.');
            }
        }
    }

    public static function defaults(): self
    {
        return new self(
            failOnSeverity: SeverityEnum::High,
            isDevelopmentDependencyIncluded: true,
            staleAfterMonths: 24,
            phpWarnDaysBeforeEndOfLife: 180,
            laravelWarnDaysBeforeEndOfLife: 180,
            isNodeEndOfLifeCheckEnabled: true,
            ignoreRules: [],
            cacheDirectory: null,
            cacheTtlHours: 24,
        );
    }

    /**
     * True when the package must not reach a public data source: the
     * lockfile marks it private, or a `privacy.skipPackages` glob matches
     * its name (case-insensitive; `*` also matches `/`).
     */
    public function isPrivatePackage(LockedPackageData $package): bool
    {
        if ($package->isPrivate) {
            return true;
        }

        foreach ($this->skippedPackagePatterns as $pattern) {
            $expression = '#^'.strtr(preg_quote($pattern, '#'), ['\*' => '.*', '\?' => '.']).'$#i';

            if (preg_match($expression, $package->name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The packages whose names may be sent to a public data source.
     *
     * @param  list<LockedPackageData>  $packages
     * @return list<LockedPackageData>
     */
    public function publicPackages(array $packages): array
    {
        return array_values(array_filter($packages, fn (LockedPackageData $package): bool => ! $this->isPrivatePackage($package)));
    }

    /**
     * Unique names of the packages kept away from public data sources, in
     * lockfile order. They stay in the local report only.
     *
     * @param  list<LockedPackageData>  $packages
     * @return list<string>
     */
    public function privatePackageNames(array $packages): array
    {
        $names = [];

        foreach ($packages as $package) {
            if ($this->isPrivatePackage($package)) {
                $names[$package->name] = $package->name;
            }
        }

        return array_values($names);
    }

    /**
     * True when an ignore rule still covers the finding on `$today`.
     *
     * `until` is inclusive. A rule whose date is before today does not match,
     * so the finding stays active. Comparison uses the UTC calendar date.
     */
    public function isIgnored(FindingData $finding, DateTimeImmutable $today): bool
    {
        foreach ($this->ignoreRules as $ignoreRule) {
            if (! $this->ruleMatchesFinding($ignoreRule, $finding)) {
                continue;
            }

            if ($this->isRuleExpired($ignoreRule, $today)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Warning lines for ignore rules that matched and have expired.
     *
     * A rule that expired is not mentioned when a different active rule still
     * ignores the same finding. The CLI prints these lines. This method does
     * not write to the output.
     *
     * @param  list<FindingData>  $findings
     * @return list<string>
     */
    public function expiredIgnoreWarnings(array $findings, DateTimeImmutable $today): array
    {
        $warnings = [];

        foreach ($findings as $finding) {
            if ($this->isIgnored($finding, $today)) {
                continue;
            }

            foreach ($this->ignoreRules as $ignoreRule) {
                $until = $ignoreRule['until'] ?? null;

                if (! is_string($until) || ! $this->ruleMatchesFinding($ignoreRule, $finding) || ! $this->isRuleExpired($ignoreRule, $today)) {
                    continue;
                }

                $warnings[] = sprintf(
                    'Ignore rule for %s on %s expired on %s. The finding is active again. Reason was: %s',
                    $ignoreRule['checkId'],
                    $ignoreRule['subject'],
                    $until,
                    $ignoreRule['reason'],
                );
            }
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $ignoreRule
     */
    private function assertIgnoreRule(array $ignoreRule): void
    {
        $allowedKeys = ['checkId', 'subject', 'advisoryId', 'reason', 'until'];

        foreach (array_keys($ignoreRule) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new InvalidConfigurationException('Unknown ignore rule key ['.$key.'].');
            }
        }

        foreach (['checkId', 'subject', 'reason'] as $requiredKey) {
            $value = $ignoreRule[$requiredKey] ?? null;

            if (! is_string($value) || $value === '') {
                throw new InvalidConfigurationException('Every ignore rule needs a non-empty '.$requiredKey.'.');
            }
        }

        $advisoryId = $ignoreRule['advisoryId'] ?? null;

        if ($advisoryId !== null && ! is_string($advisoryId)) {
            throw new InvalidConfigurationException('Ignore rule advisoryId must be a string or null.');
        }

        $until = $ignoreRule['until'] ?? null;

        if ($until === null) {
            return;
        }

        if (! is_string($until) || ! $this->isCalendarDate($until)) {
            throw new InvalidConfigurationException('Ignore rule until must be a Y-m-d date or null.');
        }
    }

    /**
     * @param  array{checkId: string, subject: string, advisoryId?: string|null, reason: string, until?: string|null}  $ignoreRule
     */
    private function ruleMatchesFinding(array $ignoreRule, FindingData $finding): bool
    {
        if ($ignoreRule['checkId'] !== $finding->checkId || $ignoreRule['subject'] !== $finding->subject) {
            return false;
        }

        $advisoryId = $ignoreRule['advisoryId'] ?? null;

        if ($advisoryId === null) {
            return true;
        }

        return $advisoryId === $finding->advisoryId;
    }

    /**
     * @param  array{until?: string|null}  $ignoreRule
     */
    private function isRuleExpired(array $ignoreRule, DateTimeImmutable $today): bool
    {
        $until = $ignoreRule['until'] ?? null;

        if ($until === null) {
            return false;
        }

        return $until < $today->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
    }

    private function isCalendarDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false) {
            return false;
        }

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return false;
        }

        return $parsed->format('Y-m-d') === $value;
    }
}
