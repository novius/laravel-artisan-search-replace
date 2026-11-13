<?php

namespace Novius\LaravelArtisanSearchReplace\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * Database engine specific parts of the search-replace.
 */
interface Driver
{
    /**
     * Names of the base tables (no views) of the connection's database, with the table prefix.
     *
     * @return list<string>
     */
    public function tables(Connection $connection): array;

    /**
     * Whether a column, as returned by Schema::getColumns(), can hold text to process.
     *
     * @param  array{name: string, type_name: string, type: string}  $column
     */
    public function isTextColumn(array $column): bool;

    /**
     * Adds to the query an "or where" condition, true when the column contains the needle.
     * The comparison must be case-sensitive.
     */
    public function orWhereContains(Builder $query, string $column, string $needle): void;
}
