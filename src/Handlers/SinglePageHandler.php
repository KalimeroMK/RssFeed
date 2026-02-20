<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Handlers;

/**
 * Handles single-page view for multi-page articles
 * Tries to find and fetch a single-page version of an article
 */
class SinglePageHandler
{
    /**
     * Try to get single page view
     *
     * @return array<string, mixed>|null
     */
    public function tryGetSinglePage(string $html, string $url, object $contentExtractor): ?array
    {
        // Single page functionality not yet implemented
        return null;
    }
}
