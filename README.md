# RssFeed Laravel Package

This package provides an easy way to parse RSS feeds and save them into your application. It provides features like fetching the entire content of an RSS feed, saving images found in the feed items, and getting full content of each item in the feed.

## Features

1. Parses multiple RSS feeds.
2. Save images in the RSS feed items to a storage location.
3. Retrieves the full content of each item in the RSS feed.

## Requirements

* PHP 7.4 or higher
* Laravel 8.0 or higher
* Composer

## Installation

You can install this package via Composer using:


``` bash 
composer require kalimeromk/rssfeed
```

This package uses Laravel's auto-discovery feature, so you don't need to register the service provider.

## Configuration

This package supports optional configuration.

You can publish the configuration file using:

``` bash 
php artisan vendor:publish --provider="Kalimeromk\Rssfeed\RssFeedServiceProvider" --tag="config"
```

This will publish a rssfeed.php config file to your config directory. Here you can set the XPaths for content elements.

```php
return [
    'domain_xpaths' => [
        [
            'domain' => 'mistagogia.mk',
            'content_element_xpaths' => [
                '//div[@class="single_post"]',
            ],
        ],
    ],
    'min_image_width' => 300,
    'image_storage_path' => 'images',
];

```
### In this configuration file:

* domain_xpaths: Defines specific XPaths for content elements based on the domain. This allows for precise targeting of content within the RSS feed items for each domain.
* min_image_width: Sets the minimum width for images to be considered for storage, ensuring that only images of adequate size are saved.
* image_storage_path: Specifies the path where images from RSS feed items should be stored.
## Credits

This package was created by KalimeroMK.

## License

The MIT License (MIT). Please see License File for more information.

## Usage

Below is an example of how to use this package.

```php
use Kalimeromk\Rssfeed\RssFeed;

$feedUrls = ['http://example.com/rssfeed1', 'http://example.com/rssfeed2'];
$rssFeed = new RssFeed();
$feedData = $rssFeed->parseRssFeeds($feedUrls);

foreach ($feedData as $item) {
    echo 'Title: ' . $item['title'] . PHP_EOL;
    echo 'Link: ' . $item['link'] . PHP_EOL;
    echo 'Publication Date: ' . $item['pub_date'] . PHP_EOL;
    echo 'Description: ' . $item['description'] . PHP_EOL;
    echo 'Content: ' . $item['content'] . PHP_EOL;
    echo 'Image Path: ' . $item['image_path'] . PHP_EOL;
    echo 'Channel Title: ' . $item['channel_title'] . PHP_EOL;
    echo 'Channel Link: ' . $item['channel_link'] . PHP_EOL;
    echo 'Channel Description: ' . $item['channel_description'] . PHP_EOL;
}
```
## Jobs

If you need to dispatch the RssFeed job, you can do so as follows:

```php
use Kalimeromk\Rssfeed\Jobs\RssFeedJob;

$feedUrls = ['https://example.com/rss'];

RssFeedJob::dispatch($feedUrls);
```