# RssFeed Laravel Package

This package provides an easy way to parse RSS feeds and save them into your application. It offers features like fetching the entire content of an RSS feed, saving images found in the feed items, and getting the full content of each item in the feed.

## Features

1. Parses multiple RSS feeds.
2. Saves images in the RSS feed items to a storage location.
3. Retrieves the full content of each item in the RSS feed.
4. Supports **Spatie Media Library** for storing images.

## Requirements

* PHP 7.4 or higher
* Laravel 5.5 or higher
* SimplePie PHP library 1.8 or higher
* Optional: Spatie Media Library (if enabled for image storage)

## Installation

You can install this package via Composer using:

```bash
composer require kalimeromk/rssfeed
```

This package uses Laravel's auto-discovery feature, so you don't need to register the service provider.

## Configuration

This package supports optional configuration.

You can publish the configuration file using:

```bash
php artisan vendor:publish --provider="Kalimeromk\Rssfeed\RssFeedServiceProvider" --tag="config"
```

This will publish a `rssfeed.php` config file to your `config` directory. Here you can set various options for image storage and media handling.

```php
return [
    'image_storage_path' => 'images',
    'spatie_media_type' => 'image',
    'spatie_disk' => 'public',
    'spatie_enabled' => false,
    'content_selectors' => [
    ],
    'default_selector' => '//div[contains(@class, "item-page")]
                           | //div[contains(@class, "post-content") and contains(@class, "entry-content")]',// Set to true if using Spatie Media Library
];
```

### Configuration Options:
* `image_storage_path`: Specifies the path where images from RSS feed items should be stored (if not using Spatie Media Library).
* `spatie_media_type`: Defines the media collection type when using Spatie Media Library.
* `spatie_disk`: Specifies which Laravel storage disk to use.
* `spatie_enabled`: Set to `true` if you want to store images using Spatie Media Library.
* `default_selector`: The default selector to use when extracting the full content of an RSS feed item.
* `content_selectors`: Here you can map specific domains to custom XPath selectors for fetching full content from a post. If the post URL belongs to one of these domains, its selector will be used.

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
            $i['date'] = $item->get_date();
            $i['permalink'] = $item->get_permalink();
            $i['link'] = $item->get_link();
            $result['items'][] = $i;
        }
        
        return $result;
    }
}
```

## Saving Images

You can save images found in the RSS feed items using the `saveImagesToStorage` method. This method accepts an array of image URLs and returns an array of saved image names or URLs.

### **Using Default Laravel Storage**
```php  
$images = [
    'http://example.com/image1.jpg',
    'http://example.com/image2.jpg',
];
$savedImageNames = $rssFeed->saveImagesToStorage($images);
```

### **Using Spatie Media Library**

If you have **Spatie Media Library** enabled and you want to save images to a media collection:

```php
use App\Models\Post;
use Kalimeromk\Rssfeed\RssFeed;

$rssFeed = new RssFeed(app());
$post = Post::find(1); // Model must implement HasMedia

$images = [
    'http://example.com/image1.jpg',
    'http://example.com/image2.jpg',
];
$savedMediaUrls = $rssFeed->saveImagesToStorage($images, $post);
```

### **Ensure Your Model Implements Spatie Media Library**
```php
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Post extends Model implements HasMedia
{
    use InteractsWithMedia;
}
```

## Jobs

If you need to dispatch the RssFeed job, you can do so as follows:

```php
use Kalimeromk\Rssfeed\Jobs\RssFeedJob;

$feedUrls = ['https://example.com/rss'];

RssFeedJob::dispatch($feedUrls);
```

## Credits

This package was created by KalimeroMK.

## License

The MIT License (MIT). Please see License File for more information.
