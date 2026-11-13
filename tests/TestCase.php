<?php

namespace Novius\LaravelArtisanSearchReplace\Tests;

use Novius\LaravelArtisanSearchReplace\LaravelArtisanSearchReplaceServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelArtisanSearchReplaceServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // MySQL connection configured by the DB_* environment variables (see phpunit.xml.dist)
        $app['config']->set('database.default', 'mysql');
        // As in production, whatever the default engine of the test server
        $app['config']->set('database.connections.mysql.engine', 'InnoDB');

        $app['config']->set('database.connections.memory', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
