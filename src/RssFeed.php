<?php

namespace Kalimeromk\Rssfeed;

use DOMDocument;
use DOMXPath;
use Exception;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kalimeromk\Rssfeed\Exceptions\CantOpenFileFromUrlException;
use Kalimeromk\Rssfeed\Helpers\UrlUploadedFile;
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
     * This method creates an instance of SimplePie, disables caching and ordering by date,
     * sets some default cURL options for SSL verification, and optionally sets additional cURL options.
     * It then sets the feed URL, initializes the SimplePie object, and returns it.
     *
     * @param  string  $url  The URL of the RSS feed to parse.
     * @param  mixed  $jobId  An optional job ID. Default is null.
     * @return SimplePie The initialized SimplePie object.
     *
     * @throws Exception If the SimplePie object cannot be created or initialized.
     * @throws CantOpenFileFromUrlException
     */
    public function RssFeeds(string $url, $jobId = null): SimplePie
    {
        if (! $this->urlExists($url)) {
            throw new CantOpenFileFromUrlException("Cannot open RSS feed URL: {$url}");
        }
        $simplePie = $this->app->make(SimplePie::class);

        $simplePie->enable_cache(false);
        $simplePie->enable_order_by_date(false);

        // Set default cURL options
        $simplePie->set_curl_options([
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        // Additional cURL options can be configured via SimplePie if needed

        $simplePie->set_feed_url($url);
        $simplePie->init();

        return $simplePie;
    }

    /**
     * Saves images to storage.
     *
     * This method takes an array of image URLs, downloads them, and saves them to storage.
     * It generates a random name for each image and stores the image in the path specified by the 'rssfeed.image_storage_path' configuration value.
     * If this configuration value is not set, it defaults to 'images'.
     * The images are stored with 'public' visibility.
     * The method returns an array of the generated image names.
     *
     * @param  array  $images  An array of image URLs to download and save.
     * @return array An array of the generated image names.
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
                    $media = $model->addMediaFromUrl($image)
                        ->toMediaCollection($spatieMediaType, $spatieDisk);
                } else {
                    $file->storeAs($imageStoragePath, $imageName, $spatieDisk);
                }
                $savedImageNames[] = $imageName;
            } catch (\Exception $e) {
                Log::error('Error processing image URL: '.$image, ['exception' => $e]);

                continue;
            }
        }

        return $savedImageNames;
    }

    /**
     * Infers the file extension from the URL or MIME type.
     *
     * This method first attempts to infer the file extension from the URL.
     * If the URL does not contain an extension, it falls back to mapping MIME types to extensions.
     * If the MIME type is unknown, it defaults to 'bin'.
     *
     * @param  string  $url  The URL of the file.
     * @param  string  $mimeType  The MIME type of the file.
     * @return string The inferred file extension.
     */
    private function inferExtension(string $url, ?string $mimeType): string
    {
        // Attempt to infer the extension from the URL
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $pathInfo = pathinfo($path);
        if (isset($pathInfo['extension'])) {
            return $pathInfo['extension'];
        }

        // Fallback to mapping MIME types to extensions
        $mimeTypeMapping = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            // Add more MIME type mappings as needed
        ];

        return $mimeType !== null ? ($mimeTypeMapping[$mimeType] ?? 'bin') : 'bin'; // Default to 'bin' if MIME type is unknown
    }

    /**
     * Extracts the image source URL from the provided HTML description.
     *
     * This method uses a regular expression to match an <img> tag and its source URL in the provided HTML description.
     * If an <img> tag is found, the source URL is returned. If no <img> tag is found, the method returns null.
     *
     * @param  string  $content  The HTML description from which to extract the image source URL.
     * @return string|null The extracted image source URL, or null if no <img> tag is found.
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
     */
    public function extractImagesFromItem(object $item): array
    {
        $images = [];
        $baseUrl = method_exists($item, 'get_link') ? (string) $item->get_link() : null;

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
                $this->addImageUrl($images, $this->resolveUrl((string) $url, $baseUrl), $type);
            }
        }

        $images = array_merge($images, $this->extractImagesFromMediaRss($item, $baseUrl));

        $content = method_exists($item, 'get_content') ? (string) $item->get_content() : '';
        $description = method_exists($item, 'get_description') ? (string) $item->get_description() : '';
        $images = array_merge($images, $this->extractImagesFromHtml($content, $baseUrl));
        $images = array_merge($images, $this->extractImagesFromHtml($description, $baseUrl));

        return array_values(array_unique(array_filter($images)));
    }

    private function extractImagesFromMediaRss(object $item, ?string $baseUrl): array
    {
        $images = [];
        if (! method_exists($item, 'get_item_tags')) {
            return $images;
        }

        $namespace = 'http://search.yahoo.com/mrss/';
        foreach (['content', 'thumbnail'] as $tag) {
            $tags = $item->get_item_tags($namespace, $tag) ?? [];
            foreach ($tags as $tagData) {
                $attribs = $tagData['attribs'][''] ?? [];
                $url = $attribs['url'] ?? null;
                $type = $attribs['type'] ?? null;
                $this->addImageUrl($images, $this->resolveUrl((string) $url, $baseUrl), $type);
            }
        }

        return $images;
    }

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

    private function extractImagesFromHtml(string $html, ?string $baseUrl): array
    {
        $images = [];
        if (trim($html) === '') {
            return $images;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $tags = $dom->getElementsByTagName('img');
        foreach ($tags as $tag) {
            if (! $tag instanceof \DOMElement) {
                continue;
            }
            $src = $tag->getAttribute('src');
            $dataSrc = $tag->getAttribute('data-src');
            $dataOriginal = $tag->getAttribute('data-original');
            $srcset = $tag->getAttribute('srcset');
            $dataSrcset = $tag->getAttribute('data-srcset');

            $candidate = $this->parseSrcset($srcset ?: $dataSrcset) ?: ($src ?: $dataSrc ?: $dataOriginal);
            $this->addImageUrl($images, $this->resolveUrl($candidate, $baseUrl), 'image/unknown');
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
            if ($this->endsWith($descriptor, 'w')) {
                $score = (int) rtrim($descriptor, 'w');
            } elseif ($this->endsWith($descriptor, 'x')) {
                $score = (int) (float) rtrim($descriptor, 'x');
            }
            if ($url && $score >= $bestScore) {
                $bestUrl = $url;
                $bestScore = $score;
            }
        }

        return $bestUrl;
    }

    private function resolveUrl(?string $url, ?string $baseUrl): ?string
    {
        if (! $url) {
            return null;
        }
        if ($this->startsWith($url, 'data:')) {
            return null;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if ($this->startsWith($url, '//')) {
            $scheme = parse_url((string) $baseUrl, PHP_URL_SCHEME) ?: 'https';
            return $scheme.':'.$url;
        }
        if (! $baseUrl) {
            return $url;
        }
        $baseParts = parse_url($baseUrl);
        if (empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return $url;
        }
        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $basePath = $baseParts['path'] ?? '/';
        $baseDir = rtrim(str_replace('\\', '/', dirname($basePath)), '/');

        if ($this->startsWith($url, '/')) {
            return $scheme.'://'.$host.$url;
        }

        return $scheme.'://'.$host.$baseDir.'/'.$url;
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
        return $this->startsWith(strtolower($type), 'image/');
    }

    private function startsWith(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    private function endsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return substr($haystack, -strlen($needle)) === $needle;
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
     *
     * This method sends a GET request to the provided URL with specific headers and options.
     * The 'verify' option is set to false to skip SSL verification, and the 'timeout' option is set to 60 seconds.
     * The headers include a specific 'User-Agent' and 'Accept' values.
     * If the GET request is successful, the method returns true.
     * If the GET request fails (throws an exception), the method returns false.
     *
     * @param  string  $url  The URL to check.
     * @return bool Returns true if the URL exists (the GET request is successful), false otherwise.
     *
     * @throws Exception If the GET request fails.
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

    public function parseRssFeeds(string $url): array
    {
        $feed = new SimplePie;
        $feed->set_feed_url($url);
        $feed->enable_cache(false);
        $feed->init();
        $feed->handle_content_type();

        $parsedItems = [];

        $items = $feed->get_items() ?? [];
        foreach ($items as $item) {
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

            if ($content === $description || strlen(strip_tags($content)) < 200) {
                if (is_string($link)) {
                    $content = $this->fetchFullContentFromPost($link);
                }
            }

            $parsedItems[] = [
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

        return $parsedItems;
    }

    /**
     * Parse RSS feeds and extract clean text content.
     * This method is similar to parseRssFeeds but returns clean text
     * without HTML, ads, donation forms, etc.
     */
    public function parseRssFeedsClean(string $url): array
    {
        $items = $this->parseRssFeeds($url);
        
        foreach ($items as &$item) {
            // Extract clean text from HTML content
            if (! empty($item['content'])) {
                $item['content'] = $this->extractTextContent($item['content']);
            }
            // Also clean up description
            if (! empty($item['description'])) {
                $item['description'] = $this->extractTextContent($item['description']);
            }
        }
        
        return $items;
    }

    /**
     * Fetches the full content from a post URL using a domain-specific XPath selector.
     * Removes unwanted elements (donations, ads, etc.) and extracts clean text.
     */
    public function fetchFullContentFromPost(string $postUrl): string
    {
        try {
            $response = Http::retry(
                (int) config('rssfeed.http_retry_times', 2),
                (int) config('rssfeed.http_retry_sleep_ms', 200)
            )->timeout((int) config('rssfeed.http_timeout', 15))
            ->withOptions([
                'verify' => (bool) config('rssfeed.http_verify_ssl', true),
            ])->get($postUrl);

            if ($response->failed()) {
                return '';
            }

            $html = $response->body();

            libxml_use_internal_errors(true);

            $dom = new DOMDocument;
            $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
            libxml_clear_errors();

            $xpath = new DOMXPath($dom);

            // 1. Remove unwanted elements first
            $this->removeUnwantedElements($dom, $xpath);

            // 2. Extract just the host/domain from the URL
            $domain = parse_url($postUrl, PHP_URL_HOST);

            // 3. Fetch the selector from config; if not found, use the default
            $selectors = config('rssfeed.content_selectors', []);
            $selector = $selectors[$domain] ?? config('rssfeed.default_selector');

            // 4. Use the resolved selector in the XPath query
            $nodes = $xpath->query($selector);

            if (! $nodes || $nodes->length === 0) {
                return '';
            }

            $fullContent = '';
            foreach ($nodes as $node) {
                if ($node instanceof \DOMNode) {
                    $fullContent .= (string) $dom->saveHTML($node);
                }
            }

            return $fullContent;
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Fetches clean text content from a post URL.
     * Removes unwanted elements and extracts only the article text.
     */
    public function fetchCleanTextFromPost(string $postUrl): string
    {
        $html = $this->fetchFullContentFromPost($postUrl);
        return $this->extractTextContent($html);
    }

    /**
     * Remove unwanted elements from DOM before content extraction.
     */
    private function removeUnwantedElements(DOMDocument $dom, DOMXPath $xpath): void
    {
        $removeSelectors = config('rssfeed.remove_selectors', []);
        
        foreach ($removeSelectors as $selector) {
            $xpathExpr = $this->cssSelectorToXPath($selector);
            $nodes = $xpath->query($xpathExpr);
            
            if ($nodes) {
                foreach ($nodes as $node) {
                    if ($node instanceof \DOMNode && $node->parentNode) {
                        $node->parentNode->removeChild($node);
                    }
                }
            }
        }
    }

    /**
     * Convert simple CSS selector to XPath.
     * Supports: .class, #id, tag, [attr], [attr=value], tag.class
     */
    private function cssSelectorToXPath(string $selector): string
    {
        $selector = trim($selector);
        
        // Handle attribute contains selector [class*="value"]
        if (preg_match('/^\[(\w+)\*="([^"]+)"\]$/', $selector, $matches)) {
            return "//*[contains(@{$matches[1]}, '{$matches[2]}')]";
        }
        
        // Handle attribute selector [attr=value]
        if (preg_match('/^\[(\w+)="([^"]+)"\]$/', $selector, $matches)) {
            return "//*[@{$matches[1]}='{$matches[2]}']";
        }
        
        // Handle ID selector #id
        if (substr($selector, 0, 1) === '#') {
            $id = substr($selector, 1);
            return "//*[@id='{$id}']";
        }
        
        // Handle class selector .class
        if (substr($selector, 0, 1) === '.'){
            $class = substr($selector, 1);
            return "//*[contains(@class, '{$class}')]";
        }
        
        // Handle tag.class
        if (strpos($selector, '.') !== false) {
            list($tag, $class) = explode('.', $selector, 2);
            return "//{$tag}[contains(@class, '{$class}')]";
        }
        
        // Handle tag#id
        if (strpos($selector, '#') !== false) {
            list($tag, $id) = explode('#', $selector, 2);
            return "//{$tag}[@id='{$id}']";
        }
        
        // Default: tag name
        return "//{$selector}";
    }

    /**
     * Extract clean text content from HTML.
     * Removes scripts, styles, and normalizes whitespace.
     */
    private function extractTextContent(string $html): string
    {
        if (empty($html)) {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        // Remove script and style elements
        $xpath = new DOMXPath($dom);
        $scripts = $xpath->query('//script|//style|//noscript|//iframe|//embed|//object');
        foreach ($scripts as $script) {
            if ($script->parentNode) {
                $script->parentNode->removeChild($script);
            }
        }

        // Get text content
        $text = $dom->textContent;
        
        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        // Remove common donation/payment text patterns
        $text = $this->removeDonationTextPatterns($text);
        
        return $text;
    }

    /**
     * Remove common donation and payment text patterns.
     */
    private function removeDonationTextPatterns(string $text): string
    {
        $patterns = [
            // Bulgarian donation patterns (from your example)
            '/дарител/is',
            '/Donation Amount/is',
            '/Превод от чужд език/is',
            '/Авторски хонорар/is',
            '/Такса за поддръжка/is',
            '/Избрана от Вас сума/is',
            '/Дарете сега/is',
            '/Donation Total/is',
            '/Споделете/is',
            
            // English donation patterns
            '/donate now/i',
            '/make a donation/i',
            '/support us/i',
            '/become a patron/i',
            '/donation amount/i',
            '/credit card info/i',
            '/secure ssl encrypted payment/i',
            '/select payment method/i',
            '/personal info/i',
            '/first name/i',
            '/last name/i',
            '/email address/i',
            '/make this an anonymous donation/i',
            '/donation total/i',
            
            // Newsletter patterns
            '/subscribe to our newsletter/i',
            '/sign up for our newsletter/i',
            '/newsletter signup/i',
            '/email newsletter/i',
            
            // Generic patterns
            '/\d+\.\d{2}€/',
            '/\$\d+\.\d{2}/',
        ];
        
        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }
        
        // Clean up extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }
}
