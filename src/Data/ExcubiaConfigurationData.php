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
 */
final readonly class ExcubiaConfigurationData
{
    /**
     * @param  list<array{
     *     checkId: string,
     *     subject: string,
     *     advisoryId: string|null,
     *     reason: string,
     *     until: string|null
     * }>  $ignoreRules
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
    ) {
        if ($this->staleAfterMonths < 1) {
            throw new InvalidConfigurationException('staleAfterMonths must be at least 1.');
        }

        if ($this->phpWarnDaysBeforeEndOfLife < 0 || $this->laravelWarnDaysBeforeEndOfLife < 0) {
            throw new InvalidConfigurationException('warnDaysBeforeEol must be zero or greater.');
        }

        if ($this->cacheTtlHours < 0) {
            throw new InvalidConfigurationException('cache ttlHours must be zero or greater.');
        }

        foreach ($this->ignoreRules as $ignoreRule) {
            $this->assertIgnoreRule($ignoreRule);
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
