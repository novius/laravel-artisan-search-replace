<?php

namespace Novius\LaravelArtisanSearchReplace;

use Illuminate\Support\ServiceProvider;
use Novius\LaravelArtisanSearchReplace\Console\SearchReplaceCommand;

class LaravelArtisanSearchReplaceServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-artisan-search-replace.php', 'laravel-artisan-search-replace');
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
            ]);
        }
    }
}
