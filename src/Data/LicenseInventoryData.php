<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

/**
 * License counts of the packages `dependency-licenses` inspected, for the
 * report's optional top-level `licenses` object (#134).
 *
 * One entry per ecosystem (`composer`, `npm`): how many packages, and how
 * many of them name each license id. A package with a choice of licenses
 * counts once under each id it names; a package that declares none counts
 * under `NOASSERTION`. Ids are sorted so the JSON is stable. Names and
 * versions are not part of the inventory; the findings carry them.
 */
final readonly class LicenseInventoryData
{
    public const NO_LICENSE_KEY = 'NOASSERTION';

    /**
     * @param  array<string, array{packageCount: int, byLicense: array<string, int>}>  $ecosystems
     */
    public function __construct(public array $ecosystems) {}

    /**
     * Packages across every ecosystem.
     */
    public function packageCount(): int
    {
        return array_sum(array_column($this->ecosystems, 'packageCount'));
    }

    /**
     * License id counts summed across ecosystems, sorted by id.
     *
     * @return array<string, int>
     */
    public function byLicense(): array
    {
        $counts = [];

        foreach ($this->ecosystems as $ecosystem) {
            foreach ($ecosystem['byLicense'] as $licenseId => $count) {
                $counts[(string) $licenseId] = ($counts[(string) $licenseId] ?? 0) + $count;
            }
        }

        ksort($counts, SORT_STRING);

        return $counts;
    }
}
