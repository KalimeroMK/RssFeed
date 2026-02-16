<?php

namespace Kalimeromk\Rssfeed;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kalimeromk\Rssfeed\Extractors\ContentExtractor\ContentExtractor;
use Kalimeromk\Rssfeed\Handlers\MultiPageHandler;
use Kalimeromk\Rssfeed\Handlers\SinglePageHandler;
use Kalimeromk\Rssfeed\Services\HtmlSanitizerService;
use Kalimeromk\Rssfeed\Services\LanguageDetectionService;

/**
 * Full-Text Content Extractor
 * 
 * This class provides advanced content extraction capabilities using:
 * - Site-specific configuration files
 * - Readability algorithm for automatic content detection
 * - Multi-page article handling
 * - Single-page view detection
 * - HTML sanitization and cleaning
 */
class FullTextExtractor
{
    private Container $app;

    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    /**
     * Extract full text content from a URL
     * 
     * @return array<string, mixed>
     */
    public function extract(string $url): array
    {
        try {
            // Fetch the page
            $response = Http::withOptions([
                'verify' => config('rssfeed.http_verify_ssl', true),
            ])->timeout((int) config('rssfeed.http_timeout', 15))
                ->retry(
                    (int) config('rssfeed.http_retry_times', 2),
                    (int) config('rssfeed.http_retry_sleep_ms', 200)
                )
                ->get($url);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'content' => null,
                    'title' => null,
                    'error' => 'HTTP request failed: ' . $response->status(),
                ];
            }

            $html = $response->body();
            return $this->extractFromHtml($html, $url);

        } catch (\Exception $e) {
            Log::error('Full-text extraction failed', ['url' => $url, 'error' => $e->getMessage()]);
            return [
                'success' => false,
                'content' => null,
                'title' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract content from HTML string
     * 
     * @return array<string, mixed>
     */
    public function extractFromHtml(string $html, string $url): array
    {
        try {
            // Clean HTML
            $html = $this->cleanHtml($html);
            $html = $this->convertToUtf8($html);

            // Get content extractor
            $contentExtractor = $this->app->make(ContentExtractor::class);
            $contentExtractor->fingerprints = config('rssfeed.fingerprints', []);
            $contentExtractor->allowedParsers = config('rssfeed.allowed_parsers', ['libxml', 'html5php']);
            $contentExtractor->defaultParser = config('rssfeed.html_parser', 'html5php');
            $contentExtractor->allowParserOverride = true;

            // Try single page first if enabled
            if (config('rssfeed.singlepage_enabled', true)) {
                $singlePageHandler = $this->app->make(SinglePageHandler::class);
                $singlePageResult = $singlePageHandler->tryGetSinglePage($html, $url, $contentExtractor);
                
                if ($singlePageResult !== null) {
                    $html = $this->cleanHtml($singlePageResult['body']);
                    $html = $this->convertToUtf8($html);
                    $url = $singlePageResult['effective_url'] ?? $url;
                    // Reset extractor for new HTML
                    $contentExtractor = $this->app->make(ContentExtractor::class);
                }
            }

            // Process content
            $success = $contentExtractor->process($html, $url);

            if (! $success) {
                return [
                    'success' => false,
                    'content' => null,
                    'title' => null,
                    'error' => 'Content extraction failed',
                ];
            }

            // Handle multi-page articles if enabled
            if (config('rssfeed.multipage_enabled', true)) {
                $nextPageUrl = $contentExtractor->getNextPageUrl();
                if ($nextPageUrl) {
                    $multiPageHandler = $this->app->make(MultiPageHandler::class);
                    $extraction = [
                        'content' => $contentExtractor->getContent(),
                        'next_page_url' => $nextPageUrl,
                    ];
                    $extraction = $multiPageHandler->process($extraction, $url, $contentExtractor);
                    $content = $extraction['content'];
                } else {
                    $content = $contentExtractor->getContent();
                }
            } else {
                $content = $contentExtractor->getContent();
            }

            // Get metadata
            $title = $contentExtractor->getTitle();
            $authors = $contentExtractor->getAuthors();
            $date = $contentExtractor->getDate();
            $language = $contentExtractor->getLanguage();
            $isNativeAd = $contentExtractor->isNativeAd();

            // Detect language if not found
            if (empty($language) && config('rssfeed.detect_language', true)) {
                $languageDetector = $this->app->make(LanguageDetectionService::class);
                $language = $languageDetector->detectFromHtml($content);
            }

            // Sanitize HTML if XSS filtering is enabled
            if (config('rssfeed.xss_filter_enabled', false)) {
                $sanitizer = $this->app->make(HtmlSanitizerService::class);
                $content = $sanitizer->sanitize($content);
            }

            // Remove inline styles if configured
            if (config('rssfeed.html_purifier.remove_inline_styles', false)) {
                $sanitizer = $this->app->make(HtmlSanitizerService::class);
                $content = $sanitizer->cleanInlineStyles($content);
            }

            // Make URLs absolute
            $content = $this->makeAbsoluteUrls($content, $url);

            return [
                'success' => true,
                'content' => $content,
                'title' => $title,
                'author' => is_array($authors) && ! empty($authors) ? implode(', ', $authors) : null,
                'date' => $date,
                'language' => $language,
                'is_native_ad' => $isNativeAd,
                'url' => $url,
            ];

        } catch (\Exception $e) {
            Log::error('Content extraction failed', ['url' => $url, 'error' => $e->getMessage()]);
            return [
                'success' => false,
                'content' => null,
                'title' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Clean HTML from problematic elements
     */
    private function cleanHtml(string $html): string
    {
        // Remove strange things
        $html = str_replace('</[>', '', $html);
        
        return $html;
    }

    /**
     * Convert HTML to UTF-8 encoding
     */
    private function convertToUtf8(string $html): string
    {
        // Check for charset in meta tag
        if (preg_match('/<meta[^>]+charset=[\'"]?([a-z0-9_-]+)[\'"]?/i', $html, $matches)) {
            $charset = strtoupper($matches[1]);
            if ($charset !== 'UTF-8') {
                $html = mb_convert_encoding($html, 'HTML-ENTITIES', $charset);
            }
        }

        return $html;
    }

    /**
     * Make all URLs in HTML absolute
     */
    private function makeAbsoluteUrls(string $html, string $baseUrl): string
    {
        if (! config('rssfeed.rewrite_relative_urls', true)) {
            return $html;
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Process img src
        $images = $xpath->query('//img[@src]');
        foreach ($images as $img) {
            if ($img instanceof \DOMElement) {
                $src = $img->getAttribute('src');
                $absUrl = $this->makeAbsolute($baseUrl, $src);
                if ($absUrl) {
                    $img->setAttribute('src', $absUrl);
                }
            }
        }

        // Process a href
        $links = $xpath->query('//a[@href]');
        foreach ($links as $link) {
            if ($link instanceof \DOMElement) {
                $href = $link->getAttribute('href');
                $absUrl = $this->makeAbsolute($baseUrl, $href);
                if ($absUrl) {
                    $link->setAttribute('href', $absUrl);
                }
            }
        }

        // Extract body content
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body !== null) {
            $result = '';
            foreach ($body->childNodes as $child) {
                $result .= $dom->saveHTML($child);
            }
            return $result;
        }

        return $html;
    }

    /**
     * Make URL absolute
     */
    private function makeAbsolute(string $base, string $url): ?string
    {
        // Already absolute
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        // Protocol-relative
        if (str_starts_with($url, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $url;
        }

        // Data URI
        if (str_starts_with($url, 'data:')) {
            return null;
        }

        $baseParts = parse_url($base);
        if (empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return null;
        }

        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $basePath = $baseParts['path'] ?? '/';
        $baseDir = rtrim(str_replace('\\', '/', dirname($basePath)), '/');

        // Absolute path
        if (str_starts_with($url, '/')) {
            return $scheme . '://' . $host . $url;
        }

        // Relative path
        return $scheme . '://' . $host . $baseDir . '/' . $url;
    }
}
