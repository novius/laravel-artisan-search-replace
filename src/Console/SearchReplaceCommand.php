<?php

namespace Novius\LaravelArtisanSearchReplace\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Console\Prohibitable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Novius\LaravelArtisanSearchReplace\Database\SchemaInspector;
use Novius\LaravelArtisanSearchReplace\Database\Table;
use Novius\LaravelArtisanSearchReplace\Database\TableReplacer;
use Novius\LaravelArtisanSearchReplace\Database\TableResult;
use Novius\LaravelArtisanSearchReplace\Drivers\DriverResolver;
use Novius\LaravelArtisanSearchReplace\Exceptions\UnsupportedDriverException;
use Novius\LaravelArtisanSearchReplace\Journal\JournalEntry;
use Novius\LaravelArtisanSearchReplace\Journal\JournalWriter;
use Novius\LaravelArtisanSearchReplace\ValueReplacer;
use RuntimeException;
use stdClass;

class SearchReplaceCommand extends Command
{
    use ConfirmableTrait, Prohibitable;

    protected $signature = 'db:search-replace
                            {search : The string to search for}
                            {replace : The replacement string (can be empty)}
                            {tables?* : Restrict the replacement to these tables (wildcards allowed, e.g. "posts" or "blog_*")}
                            {--database= : The database connection to use}
                            {--skip-tables= : Comma-separated list of tables to skip (wildcards allowed)}
                            {--skip-columns= : Comma-separated list of columns to skip ("column" or "table.column", wildcards allowed)}
                            {--include-columns= : Comma-separated list of the only columns to process ("column" or "table.column", wildcards allowed)}
                            {--journal= : Write every change to this file, to be able to revert them with db:search-replace:revert}
                            {--dry-run : Run the search/replace and report the changes, without saving them}
                            {--force : Force the operation to run when in production}';

    protected $description = 'Search and replace a string in all text columns of the database, PHP serialized and JSON data included';

    private const CONFIG = 'laravel-artisan-search-replace';

    public function handle(DatabaseManager $databaseManager, DriverResolver $drivers): int
    {
        if ($this->isProhibited()) {
            return self::FAILURE;
        }

        $search = $this->argument('search');
        $replace = $this->argument('replace');
        if (! is_string($search) || ! is_string($replace)) {
            $this->components->error('The search and replace arguments must be strings.');

            return self::FAILURE;
        }

        if ($search === '') {
            $this->components->error('The search string cannot be empty.');

            return self::FAILURE;
        }

        if ($search === $replace) {
            $this->components->warn('The replacement string is identical to the search string: nothing to do.');

            return self::SUCCESS;
        }

        $database = $this->option('database');
        $connection = $databaseManager->connection(is_string($database) ? $database : null);
        try {
            $driver = $drivers->resolve($connection);
        } catch (UnsupportedDriverException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $inspector = new SchemaInspector($connection, $driver);
        $tables = $this->resolveTables($inspector->tables());
        if ($tables === []) {
            $this->components->warn('No table to process.');

            return self::SUCCESS;
        }

        $journal = null;
        $journalPath = $this->option('journal');
        if (is_string($journalPath) && $journalPath !== '') {
            try {
                $journal = new JournalWriter($journalPath, [
                    'connection' => $connection->getName(),
                    'database' => $connection->getDatabaseName(),
                    'search' => $search,
                    'replace' => $replace,
                    'dry_run' => $dryRun,
                    'created_at' => now()->toIso8601String(),
                ]);
            } catch (RuntimeException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $tableReplacer = new TableReplacer(
            $connection,
            $driver,
            new ValueReplacer($search, $replace, $this->allowedClasses()),
            max(1, (int) config(self::CONFIG.'.chunk_size', 1000)),
        );

        $beforeUpdate = $journal === null ? null : static function (string $table, array $keys, string $column, string $old, string $new) use ($journal): void {
            $journal->write(new JournalEntry($table, $keys, $column, $old, $new));
        };

        /** @var array<string, TableResult> $results */
        $results = [];
        $withoutPrimaryKey = [];

        try {
            foreach ($tables as $name) {
                $table = $inspector->table($name);
                if ($table->primaryKey === []) {
                    $withoutPrimaryKey[] = $name;

                    continue;
                }

                $columns = $this->resolveColumns($table);
                if ($columns === []) {
                    continue;
                }

                $this->components->task($name, function () use ($tableReplacer, $table, $columns, $dryRun, $beforeUpdate, &$results) {
                    $results[$table->name] = $tableReplacer->run($table, $columns, $dryRun, $beforeUpdate);
                });
            }
        } finally {
            $journal?->close();
        }

        $this->report($results, $withoutPrimaryKey, $dryRun, $journal);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $allTables
     * @return list<string>
     */
    private function resolveTables(array $allTables): array
    {
        $patterns = array_values(array_filter((array) $this->argument('tables'), is_string(...)));
        $explicit = [];

        if ($patterns === []) {
            $selected = $allTables;
        } else {
            $selected = [];
            foreach ($patterns as $pattern) {
                $matches = array_filter($allTables, static fn (string $table): bool => Str::is($pattern, $table));
                if ($matches === []) {
                    $this->components->warn(sprintf('No table matches [%s].', $pattern));
                }
                if (! str_contains($pattern, '*')) {
                    $explicit[] = $pattern;
                }
                $selected = [...$selected, ...$matches];
            }
            $selected = array_unique($selected);
        }

        $skipped = $this->listOption('skip-tables');
        $skippedByConfig = $this->listConfig('skip_tables');

        // Tables skipped by the configuration are processed when explicitly named
        return array_values(array_filter($selected, static fn (string $table): bool => ! Str::is($skipped, $table)
            && (in_array($table, $explicit, true) || ! Str::is($skippedByConfig, $table))));
    }

    /**
     * @return list<string>
     */
    private function resolveColumns(Table $table): array
    {
        $skipped = [...$this->listConfig('skip_columns'), ...$this->listOption('skip-columns')];
        $included = $this->listOption('include-columns');

        return array_values(array_filter($table->textColumns, static function (string $column) use ($table, $skipped, $included): bool {
            $names = [$column, $table->name.'.'.$column];
            $matches = static fn (array $patterns): bool => $patterns !== [] && (Str::is($patterns, $names[0]) || Str::is($patterns, $names[1]));

            return ($included === [] || $matches($included)) && ! $matches($skipped);
        }));
    }

    /**
     * @param  array<string, TableResult>  $results
     * @param  list<string>  $withoutPrimaryKey
     */
    private function report(array $results, array $withoutPrimaryKey, bool $dryRun, ?JournalWriter $journal): void
    {
        $rows = [];
        $total = 0;
        $warnings = [];
        foreach ($results as $table => $result) {
            foreach ($result->replacements as $column => $count) {
                if ($count > 0 || $this->output->isVerbose()) {
                    $rows[] = [$table, $column, $count];
                }
            }
            $total += $result->total();
            $warnings = [...$warnings, ...$result->warnings];
        }

        $this->newLine();
        if ($rows !== []) {
            $this->table(['Table', 'Column', 'Replacements'], $rows);
        }

        if ($withoutPrimaryKey !== []) {
            $this->components->warn('Tables without primary key, skipped: '.implode(', ', $withoutPrimaryKey).'.');
        }

        if ($warnings !== []) {
            $this->components->warn(count($warnings).' value(s) left untouched:');
            $this->components->bulletList($warnings);
        }

        if ($dryRun) {
            $this->components->info(sprintf('%d %s to be made (dry run, nothing saved).', $total, Str::plural('replacement', $total)));
        } else {
            $this->components->info(sprintf('Made %d %s.', $total, Str::plural('replacement', $total)));
        }

        if ($journal !== null) {
            $this->components->twoColumnDetail('Journal', $journal->path);
            if (! $dryRun && $total > 0) {
                $this->components->twoColumnDetail('Revert with', 'php artisan db:search-replace:revert '.escapeshellarg($journal->path));
            }
        }

        if (! $dryRun && $total > 0) {
            $this->newLine();
            $this->line('  Remember to clear the caches holding replaced data (e.g. <comment>php artisan cache:clear</comment>).');
        }
    }

    /**
     * @return list<class-string>|bool
     */
    private function allowedClasses(): array|bool
    {
        $allowed = config(self::CONFIG.'.unserialize_allowed_classes', [stdClass::class]);

        if (is_bool($allowed)) {
            return $allowed;
        }

        /** @var list<class-string> */
        return array_values(array_filter((array) $allowed, is_string(...)));
    }

    /**
     * @return list<string>
     */
    private function listOption(string $name): array
    {
        $value = $this->option($name);

        return is_string($value) ? array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $item): bool => $item !== '')) : [];
    }

    /**
     * @return list<string>
     */
    private function listConfig(string $key): array
    {
        return array_values(array_filter((array) config(self::CONFIG.'.'.$key, []), is_string(...)));
    }
}
