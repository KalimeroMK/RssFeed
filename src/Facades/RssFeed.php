<?php

namespace Kalimeromk\Rssfeed\Facades;

use Illuminate\Support\Facades\Facade;

class RssFeed extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Kalimeromk\Rssfeed\RssFeed::class;
    }
}
