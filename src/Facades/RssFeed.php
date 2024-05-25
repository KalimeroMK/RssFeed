<?php

namespace Kalimeromk\Rssfeed\Facades;

use Illuminate\Support\Facades\Facade;

class RssFeed extends Facade
{
    /**
     * Gets the registered name of the RssFeed component.
     *
     * This method is used by Laravel's facade system to resolve the underlying instance of the RssFeed class.
     * It returns the fully qualified class name of the RssFeed class.
     *
     * @return string The fully qualified class name of the RssFeed class.
     */
    protected static function getFacadeAccessor()
    {
        return \Kalimeromk\Rssfeed\RssFeed::class;
    }
}
