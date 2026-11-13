<?php

namespace Novius\LaravelArtisanSearchReplace;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Novius\LaravelArtisanSearchReplace\Console\SearchReplaceCommand;
use Novius\LaravelArtisanSearchReplace\Console\SearchReplaceRevertCommand;
use Novius\LaravelArtisanSearchReplace\Drivers\DriverResolver;

class LaravelArtisanSearchReplaceServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-artisan-search-replace.php', 'laravel-artisan-search-replace');

        $this->app->singleton(DriverResolver::class, static fn (Application $app) => new DriverResolver(
            $app,
            (array) $app->make(Repository::class)->get('laravel-artisan-search-replace.drivers', []),
        ));
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/laravel-artisan-search-replace.php' => config_path('laravel-artisan-search-replace.php'),
            ], 'config');

            $this->commands([
                SearchReplaceCommand::class,
                SearchReplaceRevertCommand::class,
            ]);
        }
    }
}
