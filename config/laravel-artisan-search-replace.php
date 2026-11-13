<?php

use Novius\LaravelArtisanSearchReplace\Drivers\MySqlDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Skipped tables
    |--------------------------------------------------------------------------
    |
    | Tables never processed, unless explicitly named in the command arguments.
    | Names are given without the connection table prefix. Wildcards are allowed.
    |
    */

    'skip_tables' => [
        'migrations',
        'cache',
        'cache_locks',
        'sessions',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
    ],

    /*
    |--------------------------------------------------------------------------
    | Skipped columns
    |--------------------------------------------------------------------------
    |
    | Columns never processed: "column" (in every table) or "table.column".
    | Wildcards are allowed.
    |
    */

    'skip_columns' => [
        'password',
        'remember_token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Classes allowed when unserializing PHP serialized data
    |--------------------------------------------------------------------------
    |
    | Objects of other classes are left untouched (but the rest of the
    | serialized data is processed). true allows every class, which is
    | unsafe with untrusted data, false allows none.
    |
    */

    'unserialize_allowed_classes' => [
        stdClass::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunk size
    |--------------------------------------------------------------------------
    |
    | Number of rows containing the search string fetched at once.
    |
    */

    'chunk_size' => 1000,

    /*
    |--------------------------------------------------------------------------
    | Database drivers
    |--------------------------------------------------------------------------
    |
    | Implementation of the database specific parts, by Laravel connection
    | driver name. Classes must implement the Driver interface.
    |
    */

    'drivers' => [
        'mysql' => MySqlDriver::class,
        'mariadb' => MySqlDriver::class,
    ],

];
