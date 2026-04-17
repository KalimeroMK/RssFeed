<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Handlers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kalimeromk\Rssfeed\Extractors\ContentExtractor\ContentExtractor;
use Kalimeromk\Rssfeed\Services\UrlResolver;

class MultiPageHandler
{
    private UrlResolver $urlResolver;

    public function __construct(UrlResolver $urlResolver)
    {
        $this->urlResolver = $urlResolver;
    }

    /**
     * Process multi-page article
     *
     * @param  array<string, mixed>  $extraction
     * @return array<string, mixed>
     */
    public function process(array $extraction, string $baseUrl, ContentExtractor $contentExtractor): array
    {
        Log::debug('Processing multi-page article', ['base_url' => $baseUrl]);

        $initialContent = $extraction['content'];
        $content = is_string($initialContent) ? $initialContent : '';
        $processedUrls = [$baseUrl];

        while (! empty($extraction['next_page_url'])) {
            /** @var string $nextPageUrl */
            $nextPageUrl = $extraction['next_page_url'];
            $nextUrl = $this->urlResolver->makeAbsolute($baseUrl, $nextPageUrl);

            if (! $nextUrl || in_array($nextUrl, $processedUrls, true)) {
                Log::debug('Stopping multi-page: URL already processed or invalid', ['url' => $nextUrl]);
                break;
            }

            $processedUrls[] = $nextUrl;

            $pageResult = $this->fetchAndExtractPage($nextUrl, $contentExtractor);

            if ($pageResult === null) {
                Log::warning('Failed to fetch multi-page', ['url' => $nextUrl]);
                break;
            }

            $pageContent = $pageResult['content'];
            $content .= is_string($pageContent) ? $pageContent : '';
            $extraction['next_page_url'] = $pageResult['next_page_url'] ?? null;
        }

        $extraction['content'] = $content;

        return $extraction;
    }

    /**
     * Fetch and extract single page
     *
     * @return array<string, mixed>|null
     */
    private function fetchAndExtractPage(string $url, ContentExtractor $contentExtractor): ?array
    {
        try {
            $response = Http::withOptions([
                'verify' => config('rssfeed.http_verify_ssl', true),
            ])->timeout((int) config('rssfeed.http_timeout', 15))
                ->retry((int) config('rssfeed.http_retry_times', 2), (int) config('rssfeed.http_retry_sleep_ms', 200))
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();
            $html = $this->cleanHtml($html);
            $html = $this->convertToUtf8($html);

            $success = $contentExtractor->process($html, $url, true, true);

            if (! $success) {
                return null;
            }

            return [
                'content' => $contentExtractor->getContent(),
                'next_page_url' => $contentExtractor->getNextPageUrl(),
            ];
        } catch (\Exception $e) {
            Log::error('Error fetching multi-page', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Clean HTML from problematic elements
     */
    private function cleanHtml(string $html): string
    {
        return str_replace('</[>', '', $html);
    }

    /**
     * Convert HTML to UTF-8 encoding
     */
    private function convertToUtf8(string $html): string
    {
        if (preg_match('/<meta[^>]+charset=[\'"]?([a-z0-9_-]+)[\'"]?/i', $html, $matches)) {
            $charset = strtoupper($matches[1]);
            if ($charset !== 'UTF-8') {
                $converted = iconv($charset, 'UTF-8//IGNORE', $html);
                if ($converted !== false) {
                    $html = $converted;
                }
            }
        }

        return $html;
    }
}
