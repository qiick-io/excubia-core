<?php

declare(strict_types=1);

namespace Qiick\Excubia\Services;

use Qiick\Excubia\Contracts\DatabaseQueryContract;
use Throwable;

/**
 * Builds and parses the read-only statistics queries of
 * `laravel-database-connections` and `laravel-database-size` (#169), per
 * driver, on one open connection behind `DatabaseQueryContract`.
 *
 * - Open connections: MySQL and MariaDB read `Threads_connected` (the whole
 *   server), PostgreSQL counts `pg_stat_activity` for the current database.
 * - Size: MySQL and MariaDB sum `information_schema.tables` for the current
 *   schema, PostgreSQL asks `pg_database_size`, SQLite takes the size of
 *   the database file. The largest tables come from the same catalogs
 *   (SQLite: the `dbstat` table when the build has it, else none).
 *
 * Only counts, megabytes and table names leave this service: never the
 * database name, host or file path. Every failure becomes null (or an empty
 * table list), never an exception. MySQL queries carry a
 * `MAX_EXECUTION_TIME` hint (MariaDB reads it as a comment).
 *
 * ponytail: PostgreSQL relies on the probe's connect timeout only; the
 * catalog queries are cheap. Wrap them in `set local statement_timeout`
 * inside a transaction if a slow catalog ever blocks a run.
 */
final class DatabaseStatisticsService
{
    /**
     * Drivers whose open connections can be counted.
     */
    public const CONNECTION_COUNT_DRIVERS = ['mysql', 'mariadb', 'pgsql'];

    /**
     * Drivers whose size can be measured.
     */
    public const SIZE_DRIVERS = ['mysql', 'mariadb', 'pgsql', 'sqlite'];

    /**
     * How many of the largest tables the size check reports.
     */
    public const LARGEST_TABLE_COUNT = 5;

    /**
     * Longest table name kept; a longer one is cut.
     */
    public const TABLE_NAME_MAX_LENGTH = 64;

    private const MYSQL_TIME_LIMIT_HINT = '/*+ MAX_EXECUTION_TIME(3000) */';

    private const BYTES_PER_MEGABYTE = 1048576;

    /**
     * Open connections on the server (MySQL, MariaDB) or to the current
     * database (PostgreSQL); null for other drivers or when the query fails.
     */
    public function connectionCount(string $driver, DatabaseQueryContract $database): ?int
    {
        $query = match ($driver) {
            'mysql', 'mariadb' => "show status like 'Threads_connected'",
            'pgsql' => 'select count(*) as connection_count from pg_stat_activity where datname = current_database()',
            default => null,
        };

        if ($query === null) {
            return null;
        }

        try {
            $row = $database->select($query)[0] ?? [];
        } catch (Throwable) {
            return null;
        }

        $count = $this->column($row, $driver === 'pgsql' ? 'connection_count' : 'Value');

        return is_numeric($count) ? (int) $count : null;
    }

    /**
     * Total size and the largest tables, in megabytes rounded to two
     * decimals. `$sqliteFile` is the database file of a SQLite connection
     * (null for `:memory:`). Null for other drivers or when the total cannot
     * be read; a table list that cannot be read is empty.
     *
     * @return array{sizeMb: float, largestTables: list<array{table: string, sizeMb: float}>}|null
     */
    public function size(string $driver, DatabaseQueryContract $database, ?string $sqliteFile = null): ?array
    {
        $bytes = $this->totalBytes($driver, $database, $sqliteFile);

        if ($bytes === null) {
            return null;
        }

        return ['sizeMb' => $this->megabytes($bytes), 'largestTables' => $this->largestTables($driver, $database)];
    }

    private function totalBytes(string $driver, DatabaseQueryContract $database, ?string $sqliteFile): ?float
    {
        if ($driver === 'sqlite') {
            $bytes = $sqliteFile !== null && is_file($sqliteFile) ? @filesize($sqliteFile) : false;

            return $bytes === false ? null : (float) $bytes;
        }

        $query = match ($driver) {
            'mysql', 'mariadb' => 'select '.self::MYSQL_TIME_LIMIT_HINT.' coalesce(sum(data_length + index_length), 0) as size_bytes from information_schema.tables where table_schema = database()',
            'pgsql' => 'select pg_database_size(current_database()) as size_bytes',
            default => null,
        };

        if ($query === null) {
            return null;
        }

        try {
            $bytes = $this->column($database->select($query)[0] ?? [], 'size_bytes');
        } catch (Throwable) {
            return null;
        }

        return is_numeric($bytes) ? (float) $bytes : null;
    }

    /**
     * @return list<array{table: string, sizeMb: float}>
     */
    private function largestTables(string $driver, DatabaseQueryContract $database): array
    {
        $limit = ' order by size_bytes desc limit '.self::LARGEST_TABLE_COUNT;
        $query = match ($driver) {
            'mysql', 'mariadb' => 'select '.self::MYSQL_TIME_LIMIT_HINT." table_name as table_name, coalesce(data_length, 0) + coalesce(index_length, 0) as size_bytes from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE'".$limit,
            'pgsql' => "select c.relname as table_name, pg_total_relation_size(c.oid) as size_bytes from pg_class c join pg_namespace n on n.oid = c.relnamespace where c.relkind in ('r', 'p') and n.nspname not in ('pg_catalog', 'information_schema') and n.nspname not like 'pg_toast%'".$limit,
            default => "select name as table_name, sum(pgsize) as size_bytes from dbstat where name in (select name from sqlite_master where type = 'table') group by name".$limit,
        };

        try {
            $rows = $database->select($query);
        } catch (Throwable) {
            return [];
        }

        $tables = [];

        foreach (array_slice($rows, 0, self::LARGEST_TABLE_COUNT) as $row) {
            $name = $this->column($row, 'table_name');
            $bytes = $this->column($row, 'size_bytes');

            if (! is_string($name) || $name === '' || ! is_numeric($bytes)) {
                continue;
            }

            $tables[] = ['table' => substr($name, 0, self::TABLE_NAME_MAX_LENGTH), 'sizeMb' => $this->megabytes((float) $bytes)];
        }

        return $tables;
    }

    /**
     * A column of a row, matched without regard to case (MySQL may answer
     * `VALUE` or `TABLE_NAME`).
     *
     * @param  array<string, mixed>  $row
     */
    private function column(array $row, string $name): mixed
    {
        foreach ($row as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    private function megabytes(float $bytes): float
    {
        return round($bytes / self::BYTES_PER_MEGABYTE, 2);
    }
}
