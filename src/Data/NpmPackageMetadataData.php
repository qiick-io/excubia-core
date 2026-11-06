<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

/**
 * The parts of the npm registry's abbreviated metadata that
 * `npm-outdated` reads: every published version string and the
 * `latest` dist-tag. Nothing else of the response is kept.
 */
final readonly class NpmPackageMetadataData
{
    /**
     * @param  list<string>  $versions  published versions as the registry prints them (`4.17.21`, `5.0.0-beta.1`)
     */
    public function __construct(
        public array $versions,
        public ?string $latestVersion,
    ) {}
}
