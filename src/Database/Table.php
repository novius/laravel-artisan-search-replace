<?php

namespace Novius\LaravelArtisanSearchReplace\Database;

class Table
{
    /**
     * @param  string  $name  Name of the table, without the connection table prefix
     * @param  list<string>  $primaryKey  Columns of the primary key (empty if the table has none)
     * @param  list<string>  $textColumns  Text columns that can be processed (primary key and generated columns excluded)
     */
    public function __construct(
        public readonly string $name,
        public readonly array $primaryKey,
        public readonly array $textColumns,
    ) {}
}
