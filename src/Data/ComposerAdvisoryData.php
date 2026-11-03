<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

/**
 * One Packagist security advisory. `affectedVersions` is a Composer constraint.
 */
final readonly class ComposerAdvisoryData
{
    public function __construct(
        public string $advisoryId,
        public string $packageName,
        public string $affectedVersions,
        public ?string $severity,
        public ?string $link,
    ) {}

    /**
     * First fixed version of each affected range: the exclusive upper bound
     * of every `<` in `affectedVersions` (`>=8.0.0,<8.0.1|<7.15.2` gives
     * `8.0.1` and `7.15.2`). A range closed with `<=` has no fix yet and
     * adds nothing.
     *
     * @return list<string>
     */
    public function fixedVersions(): array
    {
        preg_match_all('/(?<![<>=!])<(?!=)\s*v?(\d[0-9A-Za-z.+-]*)/', $this->affectedVersions, $matches);

        return array_values(array_unique($matches[1]));
    }
}
