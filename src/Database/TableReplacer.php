<?php

namespace Novius\LaravelArtisanSearchReplace\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Novius\LaravelArtisanSearchReplace\Drivers\Driver;
use Novius\LaravelArtisanSearchReplace\Exceptions\UnprocessableValueException;
use Novius\LaravelArtisanSearchReplace\ValueReplacer;

/**
 * Processes the rows of a table in PHP: only the rows containing the search string are fetched,
 * by chunks paginated on the primary key, and each modified row is updated by its primary key.
 */
class TableReplacer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Driver $driver,
        private readonly ValueReplacer $replacer,
        private readonly int $chunkSize = 1000,
    ) {}

    /**
     * @param  list<string>  $columns  Columns to process
     * @param  (Closure(string $table, array<string, mixed> $keys, string $column, string $old, string $new): void)|null  $beforeUpdate
     */
    public function run(Table $table, array $columns, bool $dryRun = false, ?Closure $beforeUpdate = null): TableResult
    {
        $result = new TableResult($columns);
        if ($columns === [] || $table->primaryKey === []) {
            return $result;
        }

        $after = null;
        do {
            $rows = $this->chunk($table, $columns, $after);
            foreach ($rows as $row) {
                $this->processRow($table, $columns, $row, $dryRun, $beforeUpdate, $result);
            }
            $after = $rows === [] ? null : $rows[count($rows) - 1];
        } while (count($rows) === $this->chunkSize);

        return $result;
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, mixed>|null  $after  Last row of the previous chunk
     * @return list<array<string, mixed>>
     */
    private function chunk(Table $table, array $columns, ?array $after): array
    {
        $query = $this->connection->table($table->name)
            ->select([...$table->primaryKey, ...$columns])
            ->where(function (Builder $query) use ($columns) {
                foreach ($columns as $column) {
                    foreach ($this->replacer->needles() as $needle) {
                        $this->driver->orWhereContains($query, $column, $needle);
                    }
                }
            })
            ->limit($this->chunkSize);

        foreach ($table->primaryKey as $key) {
            $query->orderBy($key);
        }

        if ($after !== null) {
            // Keyset pagination, for a composite key (a, b): (a > ?) OR (a = ? AND b > ?)
            $query->where(function (Builder $query) use ($table, $after) {
                foreach ($table->primaryKey as $index => $key) {
                    $query->orWhere(function (Builder $query) use ($table, $after, $index, $key) {
                        foreach (array_slice($table->primaryKey, 0, $index) as $previous) {
                            $query->where($previous, '=', $after[$previous]);
                        }
                        $query->where($key, '>', $after[$key]);
                    });
                }
            });
        }

        return array_values(array_map(get_object_vars(...), $query->get()->all()));
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $row
     * @param  (Closure(string $table, array<string, mixed> $keys, string $column, string $old, string $new): void)|null  $beforeUpdate
     */
    private function processRow(Table $table, array $columns, array $row, bool $dryRun, ?Closure $beforeUpdate, TableResult $result): void
    {
        $keys = array_intersect_key($row, array_flip($table->primaryKey));

        /** @var array<string, array{string, string}> $changes */
        $changes = [];
        foreach ($columns as $column) {
            $old = $row[$column] ?? null;
            if (! is_string($old)) {
                continue;
            }

            try {
                $new = $this->replacer->replace($old);
            } catch (UnprocessableValueException $e) {
                $result->warnings[] = sprintf('%s.%s [%s]: %s', $table->name, $column, self::describeKeys($keys), $e->getMessage());

                continue;
            }

            if ($new !== $old) {
                $changes[$column] = [$old, $new];
            }
        }

        if ($changes === []) {
            return;
        }

        // Called before the update: a journal then always contains the changes made, even if the process is killed
        if ($beforeUpdate !== null) {
            foreach ($changes as $column => [$old, $new]) {
                $beforeUpdate($table->name, $keys, $column, $old, $new);
            }
        }

        if (! $dryRun) {
            try {
                $this->connection->table($table->name)
                    ->where($keys)
                    ->update(array_map(static fn (array $change): string => $change[1], $changes));
            } catch (QueryException $e) {
                $result->warnings[] = sprintf('%s [%s]: update failed: %s', $table->name, self::describeKeys($keys), $e->errorInfo[2] ?? $e->getPrevious()?->getMessage() ?? $e->getMessage());

                return;
            }
        }

        foreach (array_keys($changes) as $column) {
            $result->replacements[$column]++;
        }
    }

    /**
     * @param  array<string, mixed>  $keys
     */
    private static function describeKeys(array $keys): string
    {
        $parts = [];
        foreach ($keys as $key => $value) {
            $parts[] = $key.'='.(is_scalar($value) ? (string) $value : get_debug_type($value));
        }

        return implode(', ', $parts);
    }
}
