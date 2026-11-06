<?php

declare(strict_types=1);

namespace Qiick\Excubia\Tests\Support;

use Qiick\Excubia\Contracts\DatabaseQueryContract;
use RuntimeException;

/**
 * Database stand-in for `DatabaseStatisticsService`: answers recorded rows
 * for the first query that contains a key of `$answers` (a null answer
 * throws, like a driver error carrying a host), and keeps every query it
 * was asked.
 */
final class FakeDatabaseQuery implements DatabaseQueryContract
{
    /**
     * @var list<string>
     */
    public array $queries = [];

    /**
     * @param  array<string, list<array<string, mixed>>|null>  $answers  query fragment => rows, or null to throw
     */
    public function __construct(private readonly array $answers) {}

    public function select(string $query): array
    {
        $this->queries[] = $query;

        foreach ($this->answers as $fragment => $rows) {
            if (! str_contains($query, $fragment)) {
                continue;
            }

            if ($rows === null) {
                throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused (db-host-sentinel:3306)');
            }

            return $rows;
        }

        throw new RuntimeException('Unexpected query: '.$query);
    }
}
