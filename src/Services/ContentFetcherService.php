<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentFetcherService
{
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ];

    public function fetch(string $url): ?string
    {
        if (! $this->isUrlAllowed($url)) {
            Log::warning('SSRF blocked fetch attempt', ['url' => $url]);

            return null;
        }

        $this->applyRateLimit();

        $cacheKey = $this->httpCacheKey($url);
        $cacheTtl = (int) config('rssfeed.http_cache_time', 0);

        if ($cacheTtl > 0 && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (is_string($cached)) {
                return $cached;
            }
        }

        try {
            $response = Http::retry(
                (int) config('rssfeed.http_retry_times', 2),
                (int) config('rssfeed.http_retry_sleep_ms', 200)
            )->timeout((int) config('rssfeed.http_timeout', 15))
                ->withOptions([
                    'verify' => (bool) config('rssfeed.http_verify_ssl', true),
                ])->withHeaders([
                    'User-Agent' => $this->getUserAgent(),
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                    'Connection' => 'close',
                ])->get($url);

            if ($response->failed()) {
                return null;
            }

            $body = $response->body();

            if ($cacheTtl > 0 && $body !== '') {
                Cache::put($cacheKey, $body, $cacheTtl * 60);
            }

            return $body;
        } catch (\Exception $e) {
            Log::error('Content fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Fetch multiple URLs concurrently using HTTP pool.
     *
     * @param  array<int, string>  $urls
     * @return array<string, string|null>  URL => body (null on failure)
     */
    public function fetchBatch(array $urls): array
    {
        $results = [];
        $urlsToFetch = [];
        $cacheTtl = (int) config('rssfeed.http_cache_time', 0);

        foreach ($urls as $url) {
            if (! $this->isUrlAllowed($url)) {
                Log::warning('SSRF blocked batch fetch attempt', ['url' => $url]);
                $results[$url] = null;
                continue;
            }

            if ($cacheTtl > 0) {
                $cacheKey = $this->httpCacheKey($url);
                $cached = Cache::get($cacheKey);
                if (is_string($cached)) {
                    $results[$url] = $cached;
                    continue;
                }
            }

            $urlsToFetch[] = $url;
        }

        if ($urlsToFetch === []) {
            return $results;
        }

        $headers = [
            'User-Agent' => $this->getUserAgent(),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
            'Connection' => 'close',
        ];

        $verify = (bool) config('rssfeed.http_verify_ssl', true);
        $timeout = (int) config('rssfeed.http_timeout', 15);
        $retryTimes = (int) config('rssfeed.http_retry_times', 2);
        $retrySleep = (int) config('rssfeed.http_retry_sleep_ms', 200);

        try {
            $responses = Http::pool(function ($pool) use ($urlsToFetch, $headers, $verify, $timeout, $retryTimes, $retrySleep) {
                foreach ($urlsToFetch as $url) {
                    $pool
                        ->retry($retryTimes, $retrySleep)
                        ->timeout($timeout)
                        ->withOptions(['verify' => $verify])
                        ->withHeaders($headers)
                        ->as($url)
                        ->get($url);
                }
            });

            foreach ($urlsToFetch as $url) {
                $response = $responses[$url] ?? null;
                if ($response === null || ! method_exists($response, 'successful') || ! $response->successful()) {
                    $results[$url] = null;
                    continue;
                }

                $body = $response->body();
                $results[$url] = $body;

                if ($cacheTtl > 0 && $body !== '') {
                    Cache::put($this->httpCacheKey($url), $body, $cacheTtl * 60);
                }
            }
        } catch (\Exception $e) {
            Log::error('Batch fetch failed', ['error' => $e->getMessage()]);
            foreach ($urlsToFetch as $url) {
                if (! array_key_exists($url, $results)) {
                    $results[$url] = null;
                }
            }
        }

        return $results;
    }

    /**
     * Fetch and extract full content from multiple URLs concurrently.
     *
     * @param  array<int, string>  $urls
     * @return array<string, string>  URL => extracted content (empty string on failure)
     */
    public function fetchFullContentBatch(array $urls): array
    {
        $bodies = $this->fetchBatch($urls);
        $results = [];

        foreach ($bodies as $url => $html) {
            if ($html === null || $html === '') {
                $results[$url] = '';
                continue;
            }

            $results[$url] = $this->extractFromHtml($html, $url);
        }

        return $results;
    }

    public function fetchFullContentFromPost(string $postUrl): string
    {
        $html = $this->fetch($postUrl);

        if ($html === null) {
            return '';
        }

        return $this->extractFromHtml($html, $postUrl);
    }

    public function extractFromHtml(string $html, string $postUrl): string
    {
        try {
            libxml_use_internal_errors(true);

            $dom = new DOMDocument;
            $this->loadHtml($dom, $html);
            libxml_clear_errors();

            $xpath = new DOMXPath($dom);

            $converter = new CssSelectorConverter;
            $this->removeUnwantedElements($dom, $xpath, $converter);

            $domain = parse_url($postUrl, PHP_URL_HOST);
            $selectors = config('rssfeed.content_selectors', []);
            $selector = $selectors[$domain] ?? config('rssfeed.default_selector');

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
            Log::error('Content extraction from HTML failed', ['url' => $postUrl, 'error' => $e->getMessage()]);

            return '';
        }
    }

    private function removeUnwantedElements(DOMDocument $dom, DOMXPath $xpath, CssSelectorConverter $converter): void
    {
        $removeSelectors = config('rssfeed.remove_selectors', []);

        foreach ($removeSelectors as $selector) {
            try {
                $xpathExpr = $converter->toXPath($selector);
                $nodes = $xpath->query($xpathExpr);

                if ($nodes) {
                    foreach ($nodes as $node) {
                        if ($node instanceof \DOMNode && $node->parentNode) {
                            $node->parentNode->removeChild($node);
                        }
                    }
                }
            } catch (\InvalidArgumentException $e) {
                Log::warning('Invalid CSS selector for removal', ['selector' => $selector, 'error' => $e->getMessage()]);

                continue;
            }
        }
    }

    private function loadHtml(DOMDocument $dom, string $html): void
    {
        $charset = $this->detectCharset($html);
        if ($charset && strtoupper($charset) !== 'UTF-8') {
            $converted = iconv($charset, 'UTF-8//IGNORE', $html);
            if ($converted !== false) {
                $html = $converted;
            }
        }

        $dom->loadHTML($html);
    }

    private function detectCharset(string $html): ?string
    {
        if (preg_match('/<meta[^>]+charset=[\'"] ?([a-z0-9_-]+)[\'"] ?/i', $html, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function isUrlAllowed(string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['host'])) {
            return false;
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = $parsed['host'];

        if (str_starts_with($host, '127.') || $host === 'localhost' || $host === '::1') {
            return false;
        }

        $ip = gethostbyname($host);
        if ($ip === $host) {
            $ip = $this->resolveDns($host);
        }

        if ($ip === false) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function resolveDns(string $host): string|false
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            return false;
        }

        return $records[0]['ip'] ?? $records[0]['ipv6'] ?? false;
    }

    private function applyRateLimit(): void
    {
        $delayMs = (int) config('rssfeed.http_rate_limit_ms', 0);
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function httpCacheKey(string $url): string
    {
        $prefix = config('rssfeed.cache_prefix', 'rssfeed_');

        return $prefix.'http_'.md5($url);
    }

    private function getUserAgent(): string
    {
        if (config('rssfeed.rotate_user_agent', true)) {
            return self::USER_AGENTS[array_rand(self::USER_AGENTS)];
        }

        return config('rssfeed.user_agent', self::USER_AGENTS[0]);
    }
}
