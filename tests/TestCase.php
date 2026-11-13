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
        $app['config']->set('database.default', 'testing');
    }
}
