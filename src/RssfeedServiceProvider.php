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

    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/rssfeed.php' => config_path('/rssfeed.php')
        ]);
    }

    /**
     * Register package config.
     *
     * @return void
     */
    protected function registerConfig(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rssfeed.php', 'content_element_xpaths');
    }

}
