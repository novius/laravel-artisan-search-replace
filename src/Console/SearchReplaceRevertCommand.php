<?php

namespace Novius\LaravelArtisanSearchReplace\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Console\Prohibitable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Novius\LaravelArtisanSearchReplace\Drivers\DriverResolver;
use Novius\LaravelArtisanSearchReplace\Exceptions\InvalidJournalException;
use Novius\LaravelArtisanSearchReplace\Exceptions\UnsupportedDriverException;
use Novius\LaravelArtisanSearchReplace\Journal\JournalReader;

class SearchReplaceRevertCommand extends Command
{
    use ConfirmableTrait, Prohibitable;

    protected $signature = 'db:search-replace:revert
                            {journal : The journal file written by db:search-replace --journal}
                            {--database= : The database connection to use (defaults to the one recorded in the journal)}
                            {--dry-run : Report what would be reverted, without saving anything}
                            {--force : Force the operation to run when in production, or on another database than the journal one}';

    protected $description = 'Revert the changes made by db:search-replace, from its journal';

    public function handle(DatabaseManager $databaseManager, DriverResolver $drivers): int
    {
        if ($this->isProhibited()) {
            return self::FAILURE;
        }

        try {
            $journal = new JournalReader((string) $this->argument('journal'));
        } catch (InvalidJournalException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $header = $journal->header;
        if (($header['dry_run'] ?? false) === true) {
            $this->components->error('This journal was written by a dry run: there is nothing to revert.');

            return self::FAILURE;
        }

        $connectionName = $this->option('database') ?? $header['connection'] ?? null;
        $connection = $databaseManager->connection(is_string($connectionName) ? $connectionName : null);
        try {
            $drivers->resolve($connection);
        } catch (UnsupportedDriverException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $journalDatabase = $header['database'] ?? null;
        if (is_string($journalDatabase) && $journalDatabase !== $connection->getDatabaseName()) {
            $this->components->warn(sprintf(
                'The journal was written on the database [%s], but the current database is [%s].',
                $journalDatabase,
                $connection->getDatabaseName(),
            ));

            if (! $this->option('force') && ! $this->components->confirm('Do you really want to revert it on this database?')) {
                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        if (! $dryRun && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        /** @var array<string, array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}> $stats */
        $stats = [];
        $details = [];
        $restored = $conflicts = 0;

        try {
            foreach ($journal->entries() as $entry) {
                $statKey = $entry->table.'.'.$entry->column;
                $stats[$statKey] ??= [$entry->table, $entry->column, 0, 0, 0, 0];
                $stat = &$stats[$statKey];
                $location = sprintf('%s.%s [%s]', $entry->table, $entry->column, http_build_query($entry->keys, '', ', '));

                try {
                    $row = $connection->table($entry->table)->where($entry->keys)->first([$entry->column]);
                    $current = $row === null ? null : (((array) $row)[$entry->column] ?? null);

                    if ($row === null) {
                        $stat[5]++;
                        $details[] = $location.': row not found.';
                    } elseif (self::same($current, $entry->new)) {
                        if (! $dryRun) {
                            $connection->table($entry->table)->where($entry->keys)->update([$entry->column => $entry->old]);
                        }
                        $stat[2]++;
                        $restored++;
                    } elseif (self::same($current, $entry->old)) {
                        $stat[3]++;
                    } else {
                        $stat[4]++;
                        $conflicts++;
                        $details[] = $location.': modified since the search-replace, left untouched.';
                    }
                } catch (QueryException $e) {
                    $stat[5]++;
                    $details[] = $location.': '.($e->errorInfo[2] ?? $e->getMessage());
                }
                unset($stat);
            }
        } catch (InvalidJournalException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        if ($stats !== []) {
            $this->table(['Table', 'Column', 'Restored', 'Already reverted', 'Conflicts', 'Not found'], array_values($stats));
        }

        if ($details !== []) {
            if ($this->output->isVerbose()) {
                $this->components->bulletList($details);
            } else {
                $this->components->warn(count($details).' value(s) not restored (use -v to list them).');
            }
        }

        $this->components->info($dryRun
            ? sprintf('%d %s to be restored (dry run, nothing saved).', $restored, Str::plural('value', $restored))
            : sprintf('Restored %d %s.', $restored, Str::plural('value', $restored)));

        return $conflicts > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Whether the current value of a column is the expected one.
     * JSON documents are compared by content, as native JSON columns may reformat them.
     */
    private static function same(mixed $current, string $expected): bool
    {
        if (! is_string($current)) {
            return false;
        }

        if ($current === $expected) {
            return true;
        }

        $first = ltrim($current)[0] ?? '';
        if ($first !== '{' && $first !== '[') {
            return false;
        }

        $currentData = json_decode($current, true);
        $expectedData = json_decode($expected, true);

        return $currentData !== null && $currentData == $expectedData;
    }
}
