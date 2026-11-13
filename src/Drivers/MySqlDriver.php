<?php

namespace Novius\LaravelArtisanSearchReplace\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * MySQL and MariaDB.
 */
class MySqlDriver implements Driver
{
    private const TEXT_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json'];

    public function tables(Connection $connection): array
    {
        // Without a schema, Laravel would list the tables of every database of the server
        $tables = $connection->getSchemaBuilder()->getTables($connection->getDatabaseName());

        return array_map(static fn (array $table): string => $table['name'], $tables);
    }

    public function isTextColumn(array $column): bool
    {
        return in_array(strtolower($column['type_name']), self::TEXT_TYPES, true);
    }

    public function orWhereContains(Builder $query, string $column, string $needle): void
    {
        // Compiled as "LIKE BINARY", case-sensitive whatever the column collation
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle).'%';

        $query->orWhereLike($column, $pattern, caseSensitive: true);
    }
}
