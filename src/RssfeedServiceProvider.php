<?php

namespace Kalimeromk\Rssfeed;

use Illuminate\Support\ServiceProvider;
use Kalimeromk\Rssfeed\Extractors\ContentExtractor\ContentExtractor;
use Kalimeromk\Rssfeed\Handlers\MultiPageHandler;
use Kalimeromk\Rssfeed\Handlers\SinglePageHandler;
use Kalimeromk\Rssfeed\Services\CacheService;
use Kalimeromk\Rssfeed\Services\ContentFetcherService;
use Kalimeromk\Rssfeed\Services\CssSelectorConverter;
use Kalimeromk\Rssfeed\Services\FeedOutputService;
use Kalimeromk\Rssfeed\Services\HtmlCleanerService;
use Kalimeromk\Rssfeed\Services\HtmlSanitizerService;
use Kalimeromk\Rssfeed\Services\LanguageDetectionService;
use Kalimeromk\Rssfeed\Services\SecurityValidator;
use Kalimeromk\Rssfeed\Services\UrlResolver;

class RssfeedServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerConfig();
        $this->registerServices();
        $this->registerFacade();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/rssfeed.php' => config_path('rssfeed.php'),
        ], 'config');

        $this->publishes([
            __DIR__.'/../site_config' => base_path('site_config'),
        ], 'site-configs');
    }

    /**
     * Register package config.
     */
    private function registerConfig(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rssfeed.php', 'rssfeed');
    }

    /**
     * Register all services in the container.
     */
    private function registerServices(): void
    {
        // Cache Service
        $this->app->singleton(CacheService::class, function () {
            return new CacheService;
        });

        // HTML Sanitizer Service
        $this->app->singleton(HtmlSanitizerService::class, function () {
            return new HtmlSanitizerService;
        });

        // Security Validator
        $this->app->singleton(SecurityValidator::class, function () {
            return new SecurityValidator;
        });

        // Language Detection Service
        $this->app->singleton(LanguageDetectionService::class, function () {
            return new LanguageDetectionService;
        });

        // Feed Output Service
        $this->app->singleton(FeedOutputService::class, function () {
            return new FeedOutputService;
        });

        // Content Fetcher Service
        $this->app->singleton(ContentFetcherService::class, function () {
            return new ContentFetcherService;
        });

        // HTML Cleaner Service
        $this->app->singleton(HtmlCleanerService::class, function () {
            return new HtmlCleanerService;
        });

        // CSS Selector Converter
        $this->app->singleton(CssSelectorConverter::class, function () {
            return new CssSelectorConverter;
        });

        // URL Resolver
        $this->app->singleton(UrlResolver::class, function () {
            return new UrlResolver;
        });

        // Content Extractor
        $this->app->bind(ContentExtractor::class, function ($app) {
            return new ContentExtractor(
                config('rssfeed.site_config_path'),
                config('rssfeed.site_config_fallback_path')
            );
        });

        // Handlers
        $this->app->singleton(MultiPageHandler::class, function ($app) {
            return new MultiPageHandler($app->make(UrlResolver::class));
        });

        $this->app->singleton(SinglePageHandler::class, function () {
            return new SinglePageHandler;
        });

        // Main RssFeed class
        $this->app->singleton(RssFeed::class, function ($app) {
            return new RssFeed($app);
        });

        // Full Text Extractor
        $this->app->singleton(FullTextExtractor::class, function ($app) {
            return new FullTextExtractor($app);
        });
    }

    /**
     * Register facade accessor.
     */
    private function registerFacade(): void
    {
        $this->app->singleton('rssfeed', function ($app): RssFeed {
            return $app->make(RssFeed::class);
        });
    }
}
