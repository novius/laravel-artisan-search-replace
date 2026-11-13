<?php

namespace Novius\LaravelArtisanSearchReplace\Console;

use Illuminate\Console\Command;

class SearchReplaceCommand extends Command
{
    protected $signature = 'db:search-replace
                            {search : The string to search for}
                            {replace : The replacement string}';

    protected $description = 'Search and replace a string in all text columns of the database';

    public function handle(): int
    {
        // TODO: implement search & replace
        $this->components->warn('Not implemented yet.');

        return self::SUCCESS;
    }
}
