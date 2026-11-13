<?php

use Illuminate\Support\Facades\Schema;
use Novius\LaravelArtisanSearchReplace\Tests\TestCase;

uses(TestCase::class)->in('Unit');

uses(TestCase::class)
    ->beforeEach(fn () => Schema::dropAllTables())
    ->in('Feature');

/**
 * Path of a journal file that does not exist yet, deleted at the end of the test.
 */
function journalPath(): string
{
    $path = sys_get_temp_dir().'/search-replace-'.uniqid('', true).'.jsonl';
    register_shutdown_function(static fn () => @unlink($path));

    return $path;
}
