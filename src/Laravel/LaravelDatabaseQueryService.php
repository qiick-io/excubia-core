<?php

declare(strict_types=1);

namespace Qiick\Excubia\Laravel;

use Illuminate\Database\ConnectionInterface;
use Qiick\Excubia\Contracts\DatabaseQueryContract;

/**
 * `DatabaseQueryContract` on a Laravel database connection: runs the query
 * with `select()` and turns each row object into an array.
 */
final class LaravelDatabaseQueryService implements DatabaseQueryContract
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function select(string $query): array
    {
        $rows = [];

        foreach ($this->connection->select($query) as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }
}
