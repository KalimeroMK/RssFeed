<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentFetcherService
{
    public function fetch(string $url): ?string
    {
        try {
            $response = Http::retry(
                (int) config('rssfeed.http_retry_times', 2),
                (int) config('rssfeed.http_retry_sleep_ms', 200)
            )->timeout((int) config('rssfeed.http_timeout', 15))
                ->withOptions([
                    'verify' => (bool) config('rssfeed.http_verify_ssl', true),
                ])->get($url);

            if ($response->failed()) {
                return null;
            }

            return $response->body();
        } catch (\Exception $e) {
            Log::error('Content fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
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

            $converter = new CssSelectorConverter();
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
            $html = iconv($charset, 'UTF-8//IGNORE', $html);
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
}
