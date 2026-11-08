<?php

declare(strict_types=1);

/**
 * This repo's settings for the shared `NamingArchitectureTest.php` (qiick standards).
 *
 * Written once by the sync, never overwritten or checked: it is this repo's own.
 * Package-only rules (the Laravel-free core, what checks may use) are in
 * `PackageArchitectureTest.php`.
 */
return [
    'namespace' => 'Qiick\Excubia',

    'ignoring' => [],

    // Roles of this package beyond the shared table.
    'suffixes' => [
        'Checks' => 'Check',
        'Configuration' => 'Loader',
        'Console' => 'Command',
        'Parsers' => 'Parser',
        'Registries' => 'Registry',
        'Renderers' => 'Renderer',
    ],
];
