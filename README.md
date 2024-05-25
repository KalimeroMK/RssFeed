# RssFeed Laravel Package

This package provides an easy way to parse RSS feeds and save them into your application. It offers features like fetching the entire content of an RSS feed, saving images found in the feed items, and getting the full content of each item in the feed.

## Features

1. Parses multiple RSS feeds.
2. Saves images in the RSS feed items to a storage location.
3. Retrieves the full content of each item in the RSS feed.

## Requirements

* PHP 7.4 or higher
* SimplePie PHP library 1.8 or higher

## Installation

You can install this package via Composer using:

```bash
composer require kalimeromk/rssfeed


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
    'image_storage_path' => 'images',
];

```
### In this configuration file:
* image_storage_path: Specifies the path where images from RSS feed items should be stored.
## Credits

This package was created by KalimeroMK.

## License

The MIT License (MIT). Please see License File for more information.

## Usage

Below is an example of how to use this package.

```php
namespace App\Http\Controllers;

use Kalimeromk\Rssfeed\RssFeed;
use Illuminate\Http\Request;

class RssFeedController extends Controller
{
    public function index()
    {
        $feed = RssFeed::parseRssFeeds('https://example.com/feed/');
        
       $result = [
            'title' => $feed->get_title(),
            'description' => $feed->get_description(),
            'permalink' => $feed->get_permalink(),
            'link' => $feed->get_link(),
            'copyright' => $feed->get_copyright(),
            'language' => $feed->get_language(),
            'image_url' => $feed->get_image_url(),
            'author' => $feed->get_author()
        ];
        foreach ($feed->get_items(0, $feed->get_item_quantity()) as $item) {
            $i['title'] = $item->get_title();
            $i['description'] = $item->get_description();
            $i['id'] = $item->get_id();
            $i['content'] = $item->get_content();
            $i['thumbnail'] = $item->get_thumbnail() ?: $rssFeed->extractImageFromDescription($item->get_content());
            $i['category'] = $item->get_category();
            $i['categories'] = $item->get_categories();
            $i['author'] = $item->get_author();
            $i['authors'] = $item->get_authors();
            $i['contributor'] = $item->get_contributor();
            $i['copyright'] = $item->get_copyright();
            $i['date'] = $item->get_date();
            $i['updated_date'] = $item->get_updated_date();
            $i['local_date'] = $item->get_local_date();
            $i['permalink'] = $item->get_permalink();
            $i['link'] = $item->get_link();
            $i['links'] = $item->get_links();
            $i['enclosure'] = $item->get_enclosure();
            $i['audio_link'] = $item->get_enclosure() ? $item->get_enclosure()->get_link() : null;
            $i['enclosures'] = $item->get_enclosures();
            $i['latitude'] = $item->get_latitude();
            $i['longitude'] = $item->get_longitude();
            $i['source'] = $item->get_source();

            $result['items'][] = $i;
        }
        
        return $result;
    }
}
```

## Saving Images

You can save images found in the RSS feed items using the saveImagesToStorage method. This method accepts an array of image URLs and returns an array of saved image names.

```php  
$images = [
    'http://example.com/image1.jpg',
    'http://example.com/image2.jpg',
];
$savedImageNames = $rssFeed->saveImagesToStorage($images);
```
## Jobs

If you need to dispatch the RssFeed job, you can do so as follows:

```php
use Kalimeromk\Rssfeed\Jobs\RssFeedJob;

$feedUrls = ['https://example.com/rss'];

RssFeedJob::dispatch($feedUrls);
```