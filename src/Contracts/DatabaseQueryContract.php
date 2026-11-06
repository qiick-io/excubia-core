<?php

declare(strict_types=1);

namespace Qiick\Excubia\Contracts;

/**
 * Runs one read-only query on one open database connection, for
 * `DatabaseStatisticsService` (#169). The Laravel adapter is
 * `LaravelDatabaseQueryService`; tests fake it with recorded rows so the
 * MySQL and PostgreSQL queries are checked without a server.
 */
interface DatabaseQueryContract
{
    /**
     * Rows of `$query`, each as a column name to value map. Throws whatever
     * the driver throws; the caller turns it into "not measured".
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $query): array;
}
