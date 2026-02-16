# Laravel Full-Text RSS Package

[![PHP Version](https://img.shields.io/badge/PHP-8.0+-blue.svg)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-9.0+-orange.svg)](https://laravel.com)

A comprehensive RSS feed processing package for Laravel that extracts full-text content from RSS/Atom feeds. This package ports the powerful Full-Text RSS functionality from the original FiveFilters project to Laravel.

## ✨ Features

- 📰 **Full-Text Extraction** - Converts partial RSS feeds to complete articles
- 🤖 **Readability Algorithm** - Automatically detects main content using the Arc90 Readability algorithm
- 🌐 **Site Configs** - 1679+ site-specific configurations for better extraction
- 🖼️ **Image Processing** - Extracts and saves images with Spatie Media Library support
- 🔍 **Language Detection** - Automatically detects article language
- 🧹 **HTML Sanitization** - XSS filtering and inline style removal
- 📄 **Multi-Page Support** - Handles articles split across multiple pages
- 📝 **Multiple Output Formats** - RSS 2.0, Atom, and JSON Feed formats
- 🔐 **Security** - API key validation, domain whitelist/blacklist
- 💾 **Caching** - Built-in cache support via Laravel Cache
- ⚡ **Modern PHP** - Type-safe with PHP 8.0+ features

## 📦 Installation

```bash
composer require kalimeromk/rssfeed
```

### Publish Configuration

```bash
php artisan vendor:publish --tag=config
```

### Publish Site Configs (Optional)

```bash
php artisan vendor:publish --tag=site-configs
```

## ⚙️ Configuration

The configuration file is located at `config/rssfeed.php`:

```php
return [
    // Enable/disable the service
    'enabled' => true,

    // Security settings
    'key_required' => false,
    'api_keys' => [],
    'allowed_hosts' => [],
    'blocked_hosts' => [],

    // Feature toggles
    'singlepage_enabled' => true,
    'multipage_enabled' => true,
    'caching_enabled' => false,
    'xss_filter_enabled' => false,
    'detect_language' => true,

    // Cache settings
    'cache_time' => 10, // minutes

    // HTML parser settings
    'html_parser' => 'html5php', // or 'libxml'
];
```

## 🚀 Usage

### Basic RSS Feed Parsing

```php
use RssFeed;

// Parse RSS feed
$feed = RssFeed::RssFeeds('https://example.com/feed.xml');

// Get feed items
foreach ($feed->get_items() as $item) {
    echo $item->get_title();
    echo $item->get_description();
}
```

### Full-Text Content Extraction

```php
use Kalimeromk\Rssfeed\FullTextExtractor;

$extractor = app(FullTextExtractor::class);

// Extract from URL
$result = $extractor->extract('https://example.com/article');

if ($result['success']) {
    echo $result['title'];
    echo $result['content'];
    echo $result['author'];
    echo $result['language'];
}

// Extract from HTML string
$result = $extractor->extractFromHtml($html, 'https://example.com/article');
```

### Process Feed with Full Content

```php
use RssFeed;

$items = RssFeed::parseRssFeeds('https://example.com/feed.xml');

foreach ($items as $item) {
    echo $item['title'];
    echo $item['content']; // Full article content
    echo $item['author'];
    echo $item['language'];
}
```

### Clean Text Extraction (No HTML)

```php
$items = RssFeed::parseRssFeedsClean('https://example.com/feed.xml');

foreach ($items as $item) {
    echo $item['content']; // Plain text, no HTML
}
```

### Generate Feed Output

```php
use Kalimeromk\Rssfeed\Services\FeedOutputService;

$outputService = app(FeedOutputService::class);

// RSS 2.0
$rss = $outputService->toRss($feedData);

// Atom
$atom = $outputService->toAtom($feedData);

// JSON Feed
$json = $outputService->toJson($feedData);
```

### Image Handling

```php
// Extract images from feed item
$images = RssFeed::extractImagesFromItem($item);

// Save images to storage
$savedImages = RssFeed::saveImagesToStorage($images, $model);
```

### HTML Sanitization

```php
use Kalimeromk\Rssfeed\Services\HtmlSanitizerService;

$sanitizer = app(HtmlSanitizerService::class);

// Basic sanitization
$clean = $sanitizer->sanitize($html);

// Remove inline styles
$noStyles = $sanitizer->sanitizeWithoutStyles($html);

// Strip all HTML
$text = $sanitizer->stripAllTags($html);
```

## 🔧 Advanced Usage

### Custom Site Configuration

Create custom extraction rules in `site_config/custom/{hostname}.txt`:

```
# Example: example.com.txt
body: //article[contains(@class, 'main-content')]
title: //h1
author: //span[@class='author-name']
date: //time[@pubdate]

# Remove unwanted elements
strip_id_or_class: ads,comments,sidebar
strip: //div[@class='donation-form']
```

### Domain-Specific Selectors

Add to `config/rssfeed.php`:

```php
'content_selectors' => [
    'example.com' => '//div[@class="article-content"]',
    'news.example.com' => '//article[contains(@class, "story")]',
],
```

### Content Cleanup Rules

```php
'remove_selectors' => [
    '.donation-form',
    '.share-buttons',
    '.comments',
    '.advertisement',
],
```

## 🧪 Testing

```bash
composer test
```

## 📂 Package Structure

```
src/
├── Extractors/
│   ├── Readability/          # Arc90 Readability port
│   │   ├── Readability.php
│   │   └── JSLikeHTMLElement.php
│   └── ContentExtractor/     # Site config extraction
│       ├── ContentExtractor.php
│       └── SiteConfig.php
├── Handlers/
│   ├── MultiPageHandler.php  # Multi-page article handling
│   └── SinglePageHandler.php # Single-page view detection
├── Services/
│   ├── CacheService.php      # Laravel cache wrapper
│   ├── FeedOutputService.php # RSS/Atom/JSON generation
│   ├── HtmlSanitizerService.php
│   ├── LanguageDetectionService.php
│   └── SecurityValidator.php
├── FullTextExtractor.php     # Main extraction class
├── RssFeed.php              # Original RSS functionality
└── RssfeedServiceProvider.php

site_config/
└── standard/                # 1679+ site configurations
```

## 🔄 Migration from Original Full-Text RSS

| Original Feature | Laravel Equivalent |
|------------------|-------------------|
| `Readability.php` | `FullTextExtractor::extract()` |
| Site Config files | Same format, copied to `site_config/` |
| `makefulltextfeed.php` | `FeedOutputService` |
| `htmLawed` | `HtmlSanitizerService` (HTMLPurifier) |
| `Zend_Cache` | `CacheService` (Laravel Cache) |

## 📝 License

MIT License - see [LICENSE](LICENSE) for details.

## 🙏 Credits

This package is based on the [Full-Text RSS](https://github.com/fivefilters/full-text-rss) project by FiveFilters.org, ported to Laravel with modern PHP practices.

- Original Readability by Arc90 Labs
- Ported to PHP by Keyvan Minoukadeh
- Laravel adaptation by Zorab Shefot Bogoevski
