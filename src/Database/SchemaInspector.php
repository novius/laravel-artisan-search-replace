<?php

namespace Novius\LaravelArtisanSearchReplace\Database;

use Illuminate\Database\Connection;
use Novius\LaravelArtisanSearchReplace\Drivers\Driver;

class SchemaInspector
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Driver $driver,
    ) {}

    /**
     * Names of the tables of the database, without the connection table prefix.
     * Tables not starting with the prefix are ignored.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $prefix = $this->connection->getTablePrefix();
        $tables = [];

        foreach ($this->driver->tables($this->connection) as $name) {
            if ($prefix === '' || str_starts_with($name, $prefix)) {
                $tables[] = substr($name, strlen($prefix));
            }
        }

        return $tables;
    }

    public function table(string $name): Table
    {
        $schema = $this->connection->getSchemaBuilder();

        $primaryKey = [];
        foreach ($schema->getIndexes($name) as $index) {
            if ($index['primary']) {
                $primaryKey = $index['columns'];
            }
        }

        $textColumns = [];
        foreach ($schema->getColumns($name) as $column) {
            if ($column['generation'] === null
                && ! in_array($column['name'], $primaryKey, true)
                && $this->driver->isTextColumn($column)
            ) {
                $textColumns[] = $column['name'];
            }
        }

        return new Table($name, $primaryKey, $textColumns);
    }
}
