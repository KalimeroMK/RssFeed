<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed;

use DOMDocument;
use Exception;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kalimeromk\Rssfeed\Exceptions\CantOpenFileFromUrlException;
use Kalimeromk\Rssfeed\Helpers\UrlUploadedFile;
use Kalimeromk\Rssfeed\Services\ContentFetcherService;
use Kalimeromk\Rssfeed\Services\HtmlCleanerService;
use Kalimeromk\Rssfeed\Services\UrlResolver;
use SimplePie\SimplePie;

class RssFeed
{
    private Container $app;

    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    /**
     * Parses the RSS feeds from a given URL.
     *
     * @param  string  $url  The URL of the RSS feed to parse.
     * @param  mixed  $jobId  An optional job ID. Default is null.
     * @return SimplePie The initialized SimplePie object.
     *
     * @throws Exception If the SimplePie object cannot be created or initialized.
     * @throws CantOpenFileFromUrlException
     */
    public function rssFeeds(string $url, $jobId = null): SimplePie
    {
        if (! $this->urlExists($url)) {
            throw new CantOpenFileFromUrlException("Cannot open RSS feed URL: {$url}");
        }

        $simplePie = $this->app->make(SimplePie::class);

        $simplePie->enable_cache(false);
        $simplePie->enable_order_by_date(false);

        $verifySsl = (bool) config('rssfeed.http_verify_ssl', true);
        $simplePie->set_curl_options([
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
        ]);

        $simplePie->set_feed_url($url);
        $simplePie->init();

        return $simplePie;
    }

    /**
     * Saves images to storage.
     *
     * @param  array  $images  An array of image URLs to download and save.
     * @return array<string> An array of the generated image names.
     */
    public function saveImagesToStorage(array $images, ?object $model = null): array
    {
        $savedImageNames = [];
        $imageStoragePath = config('rssfeed.image_storage_path', 'images');
        $spatieEnabled = config('rssfeed.spatie_enabled', false);
        $spatieDisk = config('rssfeed.spatie_disk', 'public');
        $spatieMediaType = config('rssfeed.spatie_media_type', 'image');

        foreach ($images as $image) {
            if (! is_string($image) || empty($image)) {
                continue;
            }

            try {
                $file = UrlUploadedFile::createFromUrl($image);
                $extension = $file->extension();

                if (empty($extension)) {
                    $extension = $this->inferExtension($image, (string) $file->getMimeType());
                }

                $imageName = Str::random(15).'.'.$extension;

                if ($spatieEnabled && $model && \method_exists($model, 'addMediaFromUrl')) {
                    $model->addMediaFromUrl($image)
                        ->toMediaCollection($spatieMediaType, $spatieDisk);
                } else {
                    $file->storeAs($imageStoragePath, $imageName, $spatieDisk);
                }
                $savedImageNames[] = $imageName;
            } catch (Exception $e) {
                Log::error('Error processing image URL: '.$image, ['exception' => $e]);

                continue;
            }
        }

        return $savedImageNames;
    }

    /**
     * Infers the file extension from the URL or MIME type.
     */
    private function inferExtension(string $url, ?string $mimeType): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $pathInfo = pathinfo($path);
        if (isset($pathInfo['extension'])) {
            return $pathInfo['extension'];
        }

        $mimeTypeMapping = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        return $mimeType !== null ? ($mimeTypeMapping[$mimeType] ?? 'bin') : 'bin';
    }

    /**
     * Extracts the image source URL from the provided HTML description.
     */
    public function extractImageFromDescription(string $content, ?string $baseUrl = null): ?string
    {
        $tagImages = $this->extractImagesFromTagString($content);
        if (! empty($tagImages)) {
            return $tagImages[0];
        }

        $htmlImages = $this->extractImagesFromHtml($content, $baseUrl);

        return $htmlImages[0] ?? null;
    }

    /**
     * Extracts image URLs from a SimplePie item (enclosures, Media RSS, and HTML).
     *
     * @return array<int, string>
     */
    public function extractImagesFromItem(object $item): array
    {
        $images = [];
        $baseUrl = method_exists($item, 'get_link') ? (string) $item->get_link() : null;
        $resolver = $this->app->make(UrlResolver::class);

        if (method_exists($item, 'get_enclosures')) {
            $enclosures = $item->get_enclosures() ?? [];
            foreach ($enclosures as $enclosure) {
                if (! is_object($enclosure)) {
                    continue;
                }
                $type = method_exists($enclosure, 'get_type') ? (string) $enclosure->get_type() : null;
                if ($type && ! $this->isLikelyImageType($type)) {
                    continue;
                }
                $url = null;
                if (method_exists($enclosure, 'get_link')) {
                    $url = $enclosure->get_link();
                } elseif (method_exists($enclosure, 'get_url')) {
                    $url = $enclosure->get_url();
                }
                $this->addImageUrl($images, $resolver->resolveUrl((string) $url, $baseUrl), $type);
            }
        }

        $images = array_merge($images, $this->extractImagesFromMediaRss($item, $baseUrl));

        $content = method_exists($item, 'get_content') ? (string) $item->get_content() : '';
        $description = method_exists($item, 'get_description') ? (string) $item->get_description() : '';
        $images = array_merge($images, $this->extractImagesFromHtml($content, $baseUrl));
        $images = array_merge($images, $this->extractImagesFromHtml($description, $baseUrl));

        return array_values(array_unique(array_filter($images)));
    }

    /**
     * @return array<int, string>
     */
    private function extractImagesFromMediaRss(object $item, ?string $baseUrl): array
    {
        $images = [];
        if (! method_exists($item, 'get_item_tags')) {
            return $images;
        }

        $resolver = $this->app->make(UrlResolver::class);
        $namespace = 'http://search.yahoo.com/mrss/';
        foreach (['content', 'thumbnail'] as $tag) {
            $tags = $item->get_item_tags($namespace, $tag) ?? [];
            foreach ($tags as $tagData) {
                $attribs = $tagData['attribs'][''] ?? [];
                $url = $attribs['url'] ?? null;
                $type = $attribs['type'] ?? null;
                $this->addImageUrl($images, $resolver->resolveUrl((string) $url, $baseUrl), $type);
            }
        }

        return $images;
    }

    /**
     * @return array<int, string>
     */
    private function extractImagesFromTagString(string $content): array
    {
        $images = [];
        foreach (['enclosure', 'media:content', 'media:thumbnail'] as $tagName) {
            if (preg_match_all('/<'.$tagName.'\b[^>]*>/i', $content, $matches)) {
                foreach ($matches[0] as $tag) {
                    $attrs = $this->parseTagAttributes($tag);
                    $url = $attrs['url'] ?? null;
                    $type = $attrs['type'] ?? null;
                    $this->addImageUrl($images, (string) $url, $type);
                }
            }
        }

        return array_values(array_unique(array_filter($images)));
    }

    /**
     * @return array<string, string>
     */
    private function parseTagAttributes(string $tag): array
    {
        $attrs = [];
        if (preg_match_all('/([a-zA-Z0-9:_-]+)\s*=\s*([\'"])(.*?)\2/', $tag, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attrs[strtolower($match[1])] = $match[3];
            }
        }

        return $attrs;
    }

    /**
     * @return array<int, string>
     */
    private function extractImagesFromHtml(string $html, ?string $baseUrl): array
    {
        $images = [];
        if (trim($html) === '') {
            return $images;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8"?>'.$html);
        libxml_clear_errors();

        $resolver = $this->app->make(UrlResolver::class);
        $tags = $dom->getElementsByTagName('img');
        foreach ($tags as $tag) {
            $src = $tag->getAttribute('src');
            $dataSrc = $tag->getAttribute('data-src');
            $dataOriginal = $tag->getAttribute('data-original');
            $srcset = $tag->getAttribute('srcset');
            $dataSrcset = $tag->getAttribute('data-srcset');

            $candidate = $this->parseSrcset($srcset ?: $dataSrcset) ?: ($src ?: $dataSrc ?: $dataOriginal);
            $this->addImageUrl($images, $resolver->resolveUrl($candidate, $baseUrl), 'image/unknown');
        }

        return array_values(array_unique(array_filter($images)));
    }

    private function parseSrcset(?string $srcset): ?string
    {
        if (! $srcset) {
            return null;
        }

        $bestUrl = null;
        $bestScore = -1;
        $candidates = array_map('trim', explode(',', $srcset));
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $candidate);
            $url = $parts[0] ?? null;
            $descriptor = $parts[1] ?? '';
            $score = 1;
            if (str_ends_with($descriptor, 'w')) {
                $score = (int) rtrim($descriptor, 'w');
            } elseif (str_ends_with($descriptor, 'x')) {
                $score = (int) (float) rtrim($descriptor, 'x');
            }
            if ($url && $score >= $bestScore) {
                $bestUrl = $url;
                $bestScore = $score;
            }
        }

        return $bestUrl;
    }

    private function isAllowedImageExtension(string $extension): bool
    {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg'];

        return in_array(strtolower($extension), $allowed, true);
    }

    private function isLikelyImageUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' && $this->isAllowedImageExtension($extension);
    }

    private function isLikelyImageType(string $type): bool
    {
        return str_starts_with(strtolower($type), 'image/');
    }

    private function addImageUrl(array &$images, ?string $url, ?string $type): void
    {
        if (! $url) {
            return;
        }
        if ($type && ! $this->isLikelyImageType($type)) {
            return;
        }
        if (! $this->isLikelyImageUrl($url) && $type === null) {
            return;
        }
        $images[] = $url;
    }

    /**
     * Checks if a given URL exists.
     */
    public function urlExists(string $url): bool
    {
        try {
            $response = Http::retry(
                (int) config('rssfeed.http_retry_times', 2),
                (int) config('rssfeed.http_retry_sleep_ms', 200)
            )->timeout((int) config('rssfeed.http_timeout', 15))
                ->withOptions([
                    'verify' => (bool) config('rssfeed.http_verify_ssl', true),
                ])->withHeaders([
                    'User-Agent' => 'Full-Text RSS',
                    'Accept' => 'application/rss+xml, application/xml, text/xml',
                ])->get($url);

            return $response->successful();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseRssFeeds(string $url): array
    {
        $feed = $this->app->make(SimplePie::class);
        $feed->set_feed_url($url);
        $feed->enable_cache(false);
        $feed->init();
        $feed->handle_content_type();

        $parsedItems = [];
        $urlsToFetch = [];
        $fetchMap = [];

        $items = $feed->get_items();
        foreach ($items as $index => $item) {
            $title = (string) $item->get_title();
            $description = (string) $item->get_description();
            $permalink = (string) $feed->get_permalink();
            $link = $item->get_link();
            $copyright = $item->get_copyright();
            $author = $item->get_author();
            $language = (string) $feed->get_language();
            $content = (string) $item->get_content();
            $categories = $item->get_categories();
            $date = $item->get_date();
            $enclosure = $item->get_enclosure();
            $images = $this->extractImagesFromItem($item);

            if (($content === $description || strlen(strip_tags($content)) < 200) && is_string($link)) {
                $urlsToFetch[] = $link;
                $fetchMap[$index] = $link;
            }

            $parsedItems[$index] = [
                'title' => $title,
                'description' => $description,
                'permalink' => $permalink,
                'link' => $link,
                'copyright' => $copyright,
                'author' => $author,
                'language' => $language,
                'content' => $content,
                'categories' => $categories,
                'date' => $date,
                'enclosure' => $enclosure,
                'images' => $images,
                'image' => $images[0] ?? null,
            ];
        }

        if ($urlsToFetch !== []) {
            $fetcher = $this->app->make(ContentFetcherService::class);
            $fetchedContents = $fetcher->fetchFullContentBatch($urlsToFetch);

            foreach ($fetchMap as $index => $link) {
                if (isset($fetchedContents[$link])) {
                    $parsedItems[$index]['content'] = $fetchedContents[$link];

                    // Re-extract images from full content if RSS had none
                    if (empty($parsedItems[$index]['images'])) {
                        $reExtracted = $this->extractImageFromDescription($fetchedContents[$link], $link);
                        if ($reExtracted) {
                            $parsedItems[$index]['images'] = [$reExtracted];
                            $parsedItems[$index]['image'] = $reExtracted;
                        }
                    }
                }
            }
        }

        return array_values($parsedItems);
    }

    /**
     * Parse RSS feeds and extract clean text content.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseRssFeedsClean(string $url): array
    {
        $items = $this->parseRssFeeds($url);
        $cleaner = $this->app->make(HtmlCleanerService::class);

        foreach ($items as &$item) {
            if (! empty($item['content'])) {
                $item['content'] = $cleaner->extractTextContent($item['content']);
            }
            if (! empty($item['description'])) {
                $item['description'] = $cleaner->extractTextContent($item['description']);
            }
        }

        return $items;
    }

    public function fetchFullContentFromPost(string $postUrl): string
    {
        return $this->app->make(ContentFetcherService::class)->fetchFullContentFromPost($postUrl);
    }

    public function fetchCleanTextFromPost(string $postUrl): string
    {
        $html = $this->fetchFullContentFromPost($postUrl);
        $cleaner = $this->app->make(HtmlCleanerService::class);

        return $cleaner->extractTextContent($html);
    }
}
