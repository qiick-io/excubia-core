<?php

declare(strict_types=1);

namespace Qiick\Excubia\Data;

/**
 * Environment variable names for `env-example-sync` (#13): the key names
 * listed in `.env.example` and the keys that `config/*.php` reads with a
 * literal `env('KEY')`. Names only: values are dropped while parsing and
 * `.env` is never opened.
 *
 * `exampleKeys` is null when the project has no `.env.example`;
 * `configKeys` is null when it has no `config/` directory. Both are sorted.
 */
final readonly class EnvironmentKeysData
{
    /**
     * @param  list<string>|null  $exampleKeys
     * @param  array<string, list<string>>|null  $configKeys  key name => project-relative config files that read it (`config/app.php`)
     */
    public function __construct(
        public ?array $exampleKeys,
        public ?array $configKeys,
    ) {}
}
