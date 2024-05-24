<?php

namespace Kalimeromk\Rssfeed;

use Illuminate\Support\ServiceProvider;

class RssfeedServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerConfig();
        $this->registerFacade();

    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/rssfeed.php' => config_path('rssfeed.php'),
        ], 'config');
    }

    /**
     * Register package config.
     *
     * @return void
     */
    private function registerConfig(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rssfeed.php', 'image_storage_path');
    }

    private function registerFacade(): void
    {
        $this->app->bind('rssfeed', function ($app) {
            return new RssFeed($app);
        });
    }

}
