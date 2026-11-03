<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

/**
 * The runtime and framework versions the report's top-level `project`
 * object carries for excubia-app (#92).
 *
 * Versions only: no project name, path or hostname, since the app knows
 * the project from its token. `node` is the declaration as written in
 * `.nvmrc`, `.node-version` or `engines.node` (there is no Node lockfile
 * entry); a declaration with characters a version or range never uses is
 * reported as null so free text from a file cannot reach the report.
 * `php` drops the fourth `.0` of Composer's normalized form (`8.1.0.0` → `8.1.0`).
 */
final readonly class ProjectVersionsData
{
    private const NODE_DECLARATION_PATTERN = '#^[0-9A-Za-z.<>=^~*|/ -]{1,64}$#';

    public function __construct(
        public ?string $php,
        public ?string $laravel,
        public ?string $node,
    ) {}

    public static function fromSnapshot(ProjectSnapshotData $projectSnapshot): self
    {
        $node = $projectSnapshot->nodeVersionDeclaration;

        if ($node !== null && preg_match(self::NODE_DECLARATION_PATTERN, $node) !== 1) {
            $node = null;
        }

        $php = $projectSnapshot->phpVersion === null ? null : preg_replace('/^(\d+\.\d+\.\d+)\.0$/', '$1', $projectSnapshot->phpVersion);

        return new self($php, $projectSnapshot->laravelFrameworkVersion, $node);
    }
}
