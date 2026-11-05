<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

/**
 * The runtime and framework versions the report's top-level `project`
 * object carries for excubia-app (#92), with the endoflife.date cycle each
 * one matched (#123).
 *
 * Versions only: no project name, path or hostname, since the app knows
 * the project from its token. `node` is the declaration as written in
 * `.nvmrc`, `.node-version` or `engines.node` (there is no Node lockfile
 * entry); a declaration with characters a version or range never uses is
 * reported as null so free text from a file cannot reach the report.
 * `php` drops the fourth `.0` of Composer's normalized form (`8.1.0.0` → `8.1.0`).
 *
 * The cycles come from the results of `php-eol`, `laravel-eol` and
 * `node-eol`, which fetched them anyway: no extra request. A cycle is null
 * when its check did not run, was skipped, or matched no cycle.
 */
final readonly class ProjectVersionsData
{
    private const NODE_DECLARATION_PATTERN = '#^[0-9A-Za-z.<>=^~*|/ -]{1,64}$#';

    public function __construct(
        public ?string $php,
        public ?string $laravel,
        public ?string $node,
        public ?EndOfLifeCycleData $phpCycle = null,
        public ?EndOfLifeCycleData $laravelCycle = null,
        public ?EndOfLifeCycleData $nodeCycle = null,
    ) {}

    /**
     * @param  list<CheckResultData>  $checkResults
     */
    public static function fromSnapshot(ProjectSnapshotData $projectSnapshot, array $checkResults = []): self
    {
        $node = $projectSnapshot->nodeVersionDeclaration;

        if ($node !== null && preg_match(self::NODE_DECLARATION_PATTERN, $node) !== 1) {
            $node = null;
        }

        $php = $projectSnapshot->phpVersion === null ? null : preg_replace('/^(\d+\.\d+\.\d+)\.0$/', '$1', $projectSnapshot->phpVersion);
        $cycles = [];

        foreach ($checkResults as $checkResult) {
            $cycles[$checkResult->checkId] = $checkResult->endOfLifeCycle;
        }

        return new self(
            $php,
            $projectSnapshot->laravelFrameworkVersion,
            $node,
            $cycles['php-eol'] ?? null,
            $cycles['laravel-eol'] ?? null,
            $cycles['node-eol'] ?? null,
        );
    }
}
