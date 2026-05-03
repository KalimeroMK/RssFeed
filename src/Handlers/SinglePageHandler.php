<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Handlers;

use Illuminate\Support\Facades\Log;
use Kalimeromk\Rssfeed\Services\ContentFetcherService;
use Kalimeromk\Rssfeed\Services\UrlResolver;

/**
 * Handles single-page view for multi-page articles
 * Tries to find and fetch a single-page version of an article
 */
class SinglePageHandler
{
    private ContentFetcherService $fetcher;

    private UrlResolver $urlResolver;

    /** @var array<int, string> */
    private array $singlePageIndicators = [
        'single page',
        'view all',
        'full article',
        'read all',
        'all pages',
        'cijeli članak',
        'cel članek',
        'цел текст',
        'цялата статия',
        'single-page',
        'page=all',
        'view=full',
        'print=1',
        'print=true',
    ];

    public function __construct(ContentFetcherService $fetcher, UrlResolver $urlResolver)
    {
        $this->fetcher = $fetcher;
        $this->urlResolver = $urlResolver;
    }

    /**
     * Try to get single page view
     *
     * @return array<string, mixed>|null
     */
    public function tryGetSinglePage(string $html, string $url, object $contentExtractor): ?array
    {
        $singlePageUrl = $this->findSinglePageLink($html, $url, $contentExtractor);

        if ($singlePageUrl === null) {
            return null;
        }

        Log::debug('Fetching single-page version', ['url' => $url, 'single_page_url' => $singlePageUrl]);

        $body = $this->fetcher->fetch($singlePageUrl);

        if ($body === null || $body === '') {
            return null;
        }

        return [
            'body' => $body,
            'effective_url' => $singlePageUrl,
        ];
    }

    private function findSinglePageLink(string $html, string $url, object $contentExtractor): ?string
    {
        // Try site config single_page_link directives first
        if (method_exists($contentExtractor, 'getConfig') && ($config = $contentExtractor->getConfig()) !== null) {
            $singlePageLinks = [];
            if (property_exists($config, 'single_page_link')) {
                $singlePageLinks = $config->single_page_link;
            }

            foreach ($singlePageLinks as $xpath) {
                $link = $this->extractLinkByXPath($html, $xpath);
                if ($link !== null) {
                    $resolved = $this->urlResolver->makeAbsolute($url, $link);
                    if ($resolved !== null) {
                        return $resolved;
                    }
                }
            }
        }

        // Fallback: look for common single-page link patterns in HTML
        $link = $this->findLinkByIndicators($html, $url);
        if ($link !== null) {
            return $link;
        }

        // Look for query params indicating single-page view
        if (str_contains($url, '?') && str_contains($url, 'page=')) {
            return null;
        }

        return null;
    }

    private function extractLinkByXPath(string $html, string $xpath): ?string
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8"?>'.$html);
        libxml_clear_errors();

        $domXPath = new \DOMXPath($dom);
        $nodes = $domXPath->query($xpath);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);
        if ($node instanceof \DOMElement && $node->hasAttribute('href')) {
            return $node->getAttribute('href');
        }

        return $node instanceof \DOMNode ? trim($node->textContent) : null;
    }

    private function findLinkByIndicators(string $html, string $baseUrl): ?string
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8"?>'.$html);
        libxml_clear_errors();

        $links = $dom->getElementsByTagName('a');

        foreach ($links as $link) {
            if (! $link instanceof \DOMElement) {
                continue;
            }

            $href = $link->getAttribute('href');
            $text = strtolower(trim($link->textContent));
            $title = strtolower(trim($link->getAttribute('title')));
            $class = strtolower(trim($link->getAttribute('class')));

            foreach ($this->singlePageIndicators as $indicator) {
                $indicatorLower = strtolower($indicator);
                if (
                    str_contains($text, $indicatorLower)
                    || str_contains($title, $indicatorLower)
                    || str_contains($class, $indicatorLower)
                    || str_contains(strtolower($href), $indicatorLower)
                ) {
                    $resolved = $this->urlResolver->makeAbsolute($baseUrl, $href);
                    if ($resolved !== null && $resolved !== $baseUrl) {
                        return $resolved;
                    }
                }
            }
        }

        return null;
    }
}
