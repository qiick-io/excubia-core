<?php

declare(strict_types=1);

use Qiick\Excubia\Services\DatabaseStatisticsService;
use Qiick\Excubia\Tests\Support\FakeDatabaseQuery;

describe('connectionCount', function () {
    it('reads Threads_connected on MySQL and MariaDB, whatever the column case', function (string $driver) {
        $database = new FakeDatabaseQuery(["show status like 'Threads_connected'" => [['VARIABLE_NAME' => 'Threads_connected', 'VALUE' => '42']]]);

        expect((new DatabaseStatisticsService)->connectionCount($driver, $database))->toBe(42)
            ->and($database->queries)->toBe(["show status like 'Threads_connected'"]);
    })->with(['mysql', 'mariadb']);

    it('counts pg_stat_activity for the current PostgreSQL database', function () {
        $database = new FakeDatabaseQuery(['pg_stat_activity' => [['connection_count' => 7]]]);

        expect((new DatabaseStatisticsService)->connectionCount('pgsql', $database))->toBe(7)
            ->and($database->queries)->toBe(['select count(*) as connection_count from pg_stat_activity where datname = current_database()']);
    });

    it('is null for other drivers without asking, and when the query fails or answers nothing usable', function () {
        $unasked = new FakeDatabaseQuery([]);
        $service = new DatabaseStatisticsService;

        expect($service->connectionCount('sqlite', $unasked))->toBeNull()
            ->and($service->connectionCount('sqlsrv', $unasked))->toBeNull()
            ->and($unasked->queries)->toBe([])
            ->and($service->connectionCount('mysql', new FakeDatabaseQuery(['show status' => null])))->toBeNull()
            ->and($service->connectionCount('mysql', new FakeDatabaseQuery(['show status' => []])))->toBeNull()
            ->and($service->connectionCount('pgsql', new FakeDatabaseQuery(['pg_stat_activity' => [['connection_count' => 'many']]])))->toBeNull();
    });
});

describe('size', function () {
    it('sums information_schema for the current MySQL schema and keeps the five largest base tables', function () {
        $database = new FakeDatabaseQuery([
            'coalesce(sum(data_length + index_length), 0) as size_bytes' => [['size_bytes' => '536870912']],
            'table_name as table_name' => [
                ['TABLE_NAME' => 'activity_log', 'size_bytes' => 314572800],
                ['table_name' => str_repeat('t', 80), 'size_bytes' => '104857600'],
                ['table_name' => 'users', 'size_bytes' => 1572864],
                ['table_name' => '', 'size_bytes' => 10],
                ['table_name' => 'sessions', 'size_bytes' => null],
                ['table_name' => 'jobs', 'size_bytes' => 5242],
                ['table_name' => 'ignored-sixth', 'size_bytes' => 1],
            ],
        ]);
        $size = (new DatabaseStatisticsService)->size('mysql', $database);

        expect($size)->toBe([
            'sizeMb' => 512.0,
            'largestTables' => [
                ['table' => 'activity_log', 'sizeMb' => 300.0],
                ['table' => str_repeat('t', DatabaseStatisticsService::TABLE_NAME_MAX_LENGTH), 'sizeMb' => 100.0],
                ['table' => 'users', 'sizeMb' => 1.5],
            ],
        ])
            ->and($database->queries[0])->toStartWith('select /*+ MAX_EXECUTION_TIME(3000) */ ')
            ->and($database->queries[0])->toContain('from information_schema.tables where table_schema = database()')
            ->and($database->queries[1])->toStartWith('select /*+ MAX_EXECUTION_TIME(3000) */ ')
            ->and($database->queries[1])->toContain("table_type = 'BASE TABLE'")
            ->and($database->queries[1])->toEndWith('order by size_bytes desc limit 5');
    });

    it('asks pg_database_size and pg_total_relation_size on PostgreSQL, outside the system schemas', function () {
        $database = new FakeDatabaseQuery([
            'pg_database_size(current_database())' => [['size_bytes' => 8388608]],
            'pg_total_relation_size' => [['table_name' => 'orders', 'size_bytes' => 4194304]],
        ]);

        expect((new DatabaseStatisticsService)->size('pgsql', $database))->toBe(['sizeMb' => 8.0, 'largestTables' => [['table' => 'orders', 'sizeMb' => 4.0]]])
            ->and($database->queries[1])->toContain("n.nspname not in ('pg_catalog', 'information_schema')")
            ->and($database->queries[1])->toContain("c.relkind in ('r', 'p')");
    });

    it('keeps the total when the table list cannot be read, and is null when the total cannot', function () {
        $service = new DatabaseStatisticsService;

        expect($service->size('mariadb', new FakeDatabaseQuery(['sum(data_length' => [['SIZE_BYTES' => 1048576]], 'table_name as' => null])))->toBe(['sizeMb' => 1.0, 'largestTables' => []])
            ->and($service->size('pgsql', new FakeDatabaseQuery(['pg_database_size' => null])))->toBeNull()
            ->and($service->size('pgsql', new FakeDatabaseQuery(['pg_database_size' => [['size_bytes' => null]]])))->toBeNull()
            ->and($service->size('sqlsrv', new FakeDatabaseQuery([])))->toBeNull();
    });

    it('measures the SQLite file and reads its tables from dbstat when the build has it', function () {
        $project = temporaryProject(['database.sqlite' => str_repeat('x', 3 * 1048576 / 2)]);
        $database = new FakeDatabaseQuery(['from dbstat' => [['name' => 'ignored'], ['table_name' => 'posts', 'size_bytes' => 524288]]]);
        $withoutDbstat = new FakeDatabaseQuery(['from dbstat' => null]);
        $service = new DatabaseStatisticsService;

        expect($service->size('sqlite', $database, $project.'/database.sqlite'))->toBe(['sizeMb' => 1.5, 'largestTables' => [['table' => 'posts', 'sizeMb' => 0.5]]])
            ->and($database->queries)->toBe(["select name as table_name, sum(pgsize) as size_bytes from dbstat where name in (select name from sqlite_master where type = 'table') group by name order by size_bytes desc limit 5"])
            ->and($service->size('sqlite', $withoutDbstat, $project.'/database.sqlite'))->toBe(['sizeMb' => 1.5, 'largestTables' => []])
            ->and($service->size('sqlite', $withoutDbstat, $project.'/missing.sqlite'))->toBeNull()
            ->and($service->size('sqlite', $withoutDbstat))->toBeNull();
    });
});
