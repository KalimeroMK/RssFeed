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
php artisan vendor:publish --provider="Kalimeromk\Rssfeed\RssfeedServiceProvider" --tag="config"
```

This will publish a `rssfeed.php` config file to your `config` directory. Here you can set various options for image storage, HTTP behavior, and content extraction.

```php
return [
    // Storage and Spatie settings
    'image_storage_path' => 'images',
    'spatie_media_type' => 'image',
    'spatie_disk' => 'public',
    'spatie_enabled' => false,

    // HTTP options
    'http_verify_ssl' => true,
    'http_timeout' => 15,
    'http_retry_times' => 2,
    'http_retry_sleep_ms' => 200,

    // Content extraction
    'content_selectors' => [
        // 'example.com' => '//article',
    ],
    // See the published config for the full default selector union
    'default_selector' => '//article | //div[contains(@class, "entry-content")]',
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

Below are examples of how to use this package.

```php
// 1) Get normalized array of items (auto full-content extraction when needed)
use Kalimeromk\Rssfeed\RssFeed;

$rss = app(RssFeed::class);
$items = $rss->parseRssFeeds('https://example.com/feed/');

foreach ($items as $item) {
    // $item is an array with keys: title, description, permalink, link, copyright,
    // author, language, content, categories, date, enclosure
}
```

```php
// 2) Work directly with SimplePie if you need raw feed metadata
use Kalimeromk\Rssfeed\RssFeed;

$rss = app(RssFeed::class);
$feed = $rss->RssFeeds('https://example.com/feed/');

$title = $feed->get_title();
foreach ($feed->get_items() as $item) {
    // ... use SimplePie\Item API
}
```

## Saving Images

You can save images found in the RSS feed items using the `saveImagesToStorage` method. This method accepts an array of image URLs and returns an array of saved image names. If Spatie Media Library is enabled and a model is provided, media will be attached to the model's collection.

### **Using Default Laravel Storage**
```php
$images = [
    'http://example.com/image1.jpg',
    'http://example.com/image2.jpg',
];
$rss = app(\Kalimeromk\Rssfeed\RssFeed::class);
$savedImageNames = $rss->saveImagesToStorage($images);
```

### **Using Spatie Media Library**

If you have **Spatie Media Library** enabled and you want to save images to a media collection:

```php
use App\Models\Post;

$rss = app(\Kalimeromk\Rssfeed\RssFeed::class);
$post = Post::find(1); // Model should support addMediaFromUrl (Spatie Media Library)

$images = [
    'http://example.com/image1.jpg',
    'http://example.com/image2.jpg',
];
$savedImageNames = $rss->saveImagesToStorage($images, $post);
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

This package does not ship with a built-in Job class. If you need queueing, create a Laravel Job and inject the `RssFeed` service inside it.

## Credits

This package was created by KalimeroMK.

## License

The MIT License (MIT). Please see License File for more information.
