<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Data\DependencyLicensesConfigurationData;
use Qiick\Excubia\Enums\LicenseVerdictEnum;

/**
 * Small SPDX license expression reader for `dependency-licenses`.
 *
 * Grammar: `OR` and `AND` (case-insensitive, `AND` binds tighter),
 * parentheses, and `id WITH exception` (the exception does not change the
 * verdict). An id may end in `+`, `-only` or `-or-later`; the base id
 * without that suffix decides whether it is known or strong copyleft. An
 * expression that does not parse is unknown.
 *
 * `OR` lets the project pick the best option, so it keeps the mildest
 * verdict; `AND` must satisfy every operand, so it keeps the worst.
 *
 * ponytail: `KNOWN_LICENSE_IDS` is a curated list of the SPDX ids common in
 * Composer and npm trees, not the full SPDX list (no network, no new
 * dependency). A rarer valid id is reported as unknown (low) until the user
 * allows it; the upgrade path is shipping the SPDX license list as data.
 */
final class SpdxExpressionService
{
    /**
     * Base ids (upper-cased prefix match) treated as strong copyleft.
     * LGPL, MPL, EPL and CDDL are weak copyleft and count as known.
     */
    private const STRONG_COPYLEFT_PREFIXES = ['GPL-', 'AGPL-', 'SSPL-', 'EUPL-', 'OSL-', 'RPL-', 'SLEEPYCAT'];

    private const KNOWN_LICENSE_IDS = [
        '0BSD', 'AFL-2.1', 'AFL-3.0', 'Apache-1.1', 'Apache-2.0', 'Artistic-2.0', 'BlueOak-1.0.0',
        'BSD-2-Clause', 'BSD-3-Clause', 'BSD-3-Clause-Clear', 'BSD-4-Clause', 'BSL-1.0', 'CC-BY-3.0',
        'CC-BY-4.0', 'CC0-1.0', 'CDDL-1.0', 'CDDL-1.1', 'EPL-1.0', 'EPL-2.0', 'ISC', 'LGPL-2.0',
        'LGPL-2.1', 'LGPL-3.0', 'MIT', 'MIT-0', 'MPL-1.1', 'MPL-2.0', 'MS-PL', 'NCSA', 'OFL-1.1',
        'PHP-3.0', 'PHP-3.01', 'PostgreSQL', 'Python-2.0', 'Ruby', 'Unicode-3.0', 'Unicode-DFS-2016',
        'Unlicense', 'WTFPL', 'X11', 'Zlib',
    ];

    private const LICENSE_ID_PATTERN = '#^[A-Za-z0-9.+:-]+$#';

    /**
     * Verdict for a package's declared license entries. Several entries are
     * a choice (Composer's `license` array), so the mildest one wins. No
     * entry at all is unknown.
     *
     * @param  list<string>  $declaredLicenses
     */
    public function verdict(array $declaredLicenses, DependencyLicensesConfigurationData $policy): LicenseVerdictEnum
    {
        $best = null;

        foreach ($declaredLicenses as $expression) {
            $verdict = $this->evaluate($expression, fn (string $licenseId): LicenseVerdictEnum => $this->licenseIdVerdict($licenseId, $policy))
                ?? LicenseVerdictEnum::Unknown;

            $best = $best === null ? $verdict : $this->milder($best, $verdict);
        }

        return $best ?? LicenseVerdictEnum::Unknown;
    }

    /**
     * The license ids an expression names, in order and without duplicates
     * or `WITH` exceptions. An expression that does not parse is returned
     * whole, so the inventory still counts it.
     *
     * @return list<string>
     */
    public function licenseIds(string $expression): array
    {
        $licenseIds = [];
        $verdict = $this->evaluate($expression, function (string $licenseId) use (&$licenseIds): LicenseVerdictEnum {
            $licenseIds[$licenseId] = $licenseId;

            return LicenseVerdictEnum::Permitted;
        });

        return $verdict === null ? [$expression] : array_values($licenseIds);
    }

    /**
     * One id: denied, then allowed (both against the id as written, with
     * `+` spelled `-or-later`, and the base id), then strong copyleft, then
     * the known list.
     */
    public function licenseIdVerdict(string $licenseId, DependencyLicensesConfigurationData $policy): LicenseVerdictEnum
    {
        $baseId = (string) preg_replace('/(\+|-or-later|-only)$/i', '', $licenseId);
        $spellings = array_values(array_unique([$licenseId, (string) preg_replace('/\+$/', '-or-later', $licenseId), $baseId]));

        if ($policy->isDenied($spellings)) {
            return LicenseVerdictEnum::Denied;
        }

        if ($policy->isAllowed($spellings)) {
            return LicenseVerdictEnum::Permitted;
        }

        foreach (self::STRONG_COPYLEFT_PREFIXES as $prefix) {
            if (str_starts_with(strtoupper($baseId), $prefix)) {
                return LicenseVerdictEnum::StrongCopyleft;
            }
        }

        foreach (self::KNOWN_LICENSE_IDS as $knownId) {
            if (strcasecmp($knownId, $baseId) === 0) {
                return LicenseVerdictEnum::Permitted;
            }
        }

        return LicenseVerdictEnum::Unknown;
    }

    /**
     * Parses the expression and folds the verdicts of its ids; null when
     * it does not parse.
     *
     * @param  callable(string): LicenseVerdictEnum  $classify
     */
    private function evaluate(string $expression, callable $classify): ?LicenseVerdictEnum
    {
        $tokens = preg_split('/\s+|(?=[()])|(?<=[()])/', trim($expression), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false || $tokens === []) {
            return null;
        }

        $position = 0;
        $verdict = $this->disjunction($tokens, $position, $classify);

        return $position === count($tokens) ? $verdict : null;
    }

    /**
     * @param  list<string>  $tokens
     * @param  callable(string): LicenseVerdictEnum  $classify
     */
    private function disjunction(array $tokens, int &$position, callable $classify): ?LicenseVerdictEnum
    {
        $verdict = $this->conjunction($tokens, $position, $classify);

        while ($verdict !== null && $this->isOperator($tokens[$position] ?? null, 'OR')) {
            $position++;
            $next = $this->conjunction($tokens, $position, $classify);
            $verdict = $next === null ? null : $this->milder($verdict, $next);
        }

        return $verdict;
    }

    /**
     * @param  list<string>  $tokens
     * @param  callable(string): LicenseVerdictEnum  $classify
     */
    private function conjunction(array $tokens, int &$position, callable $classify): ?LicenseVerdictEnum
    {
        $verdict = $this->operand($tokens, $position, $classify);

        while ($verdict !== null && $this->isOperator($tokens[$position] ?? null, 'AND')) {
            $position++;
            $next = $this->operand($tokens, $position, $classify);
            $verdict = $next === null || $next->value > $verdict->value ? $next : $verdict;
        }

        return $verdict;
    }

    /**
     * A parenthesised expression, or one id with an optional `WITH` exception.
     *
     * @param  list<string>  $tokens
     * @param  callable(string): LicenseVerdictEnum  $classify
     */
    private function operand(array $tokens, int &$position, callable $classify): ?LicenseVerdictEnum
    {
        $token = $tokens[$position] ?? null;

        if ($token === '(') {
            $position++;
            $verdict = $this->disjunction($tokens, $position, $classify);

            if (($tokens[$position] ?? null) !== ')') {
                return null;
            }

            $position++;

            return $verdict;
        }

        if (! $this->isLicenseId($token)) {
            return null;
        }

        $position++;

        if ($this->isOperator($tokens[$position] ?? null, 'WITH')) {
            if (! $this->isLicenseId($tokens[$position + 1] ?? null)) {
                return null;
            }

            $position += 2;
        }

        return $classify((string) $token);
    }

    private function isLicenseId(?string $token): bool
    {
        if ($token === null || preg_match(self::LICENSE_ID_PATTERN, $token) !== 1) {
            return false;
        }

        return ! in_array(strtoupper($token), ['AND', 'OR', 'WITH'], true);
    }

    private function isOperator(?string $token, string $operator): bool
    {
        return $token !== null && strtoupper($token) === $operator;
    }

    private function milder(LicenseVerdictEnum $first, LicenseVerdictEnum $second): LicenseVerdictEnum
    {
        return $second->value < $first->value ? $second : $first;
    }
}
