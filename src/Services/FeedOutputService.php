<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Services;

use DateTime;
use DateTimeImmutable;

/**
 * Service for generating feed output
 * Generates RSS, Atom, and JSON feed formats
 */
class FeedOutputService
{
    /**
     * Generate RSS 2.0 feed
     *
     * @param  array<string, mixed>  $feedData
     */
    public function toRss(array $feedData): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?'.'>'.PHP_EOL;
        $xml .= '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">'.PHP_EOL;
        $xml .= '<channel>'.PHP_EOL;
        
        // Channel elements
        $xml .= $this->cdataElement('title', $feedData['title'] ?? 'RSS Feed');
        $xml .= $this->element('link', $feedData['link'] ?? '');
        $xml .= $this->cdataElement('description', $feedData['description'] ?? '');
        $xml .= $this->element('language', $feedData['language'] ?? 'en');
        $xml .= $this->element('lastBuildDate', date('r'));
        $xml .= $this->element('generator', 'Laravel RssFeed Package');

        // Items
        /** @var array<int, array<string, mixed>> $items */
        $items = $feedData['items'] ?? [];
        foreach ($items as $itemData) {
            $xml .= $this->rssItem($itemData);
        }

        $xml .= '</channel>'.PHP_EOL;
        $xml .= '</rss>';

        return $xml;
    }

    /**
     * Generate Atom feed
     *
     * @param  array<string, mixed>  $feedData
     */
    public function toAtom(array $feedData): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?'.'>'.PHP_EOL;
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom">'.PHP_EOL;
        
        // Feed elements
        $xml .= $this->cdataElement('title', $feedData['title'] ?? 'Atom Feed');
        $xml .= $this->element('link', '', ['href' => $feedData['link'] ?? '']);
        $xml .= $this->cdataElement('subtitle', $feedData['description'] ?? '');
        $xml .= $this->element('updated', $this->formatAtomDate(time()));
        $xml .= $this->element('id', $feedData['link'] ?? 'urn:uuid:' . $this->generateUuid());
        $xml .= $this->element('generator', 'Laravel RssFeed Package');

        // Entries
        /** @var array<int, array<string, mixed>> $items */
        $items = $feedData['items'] ?? [];
        foreach ($items as $itemData) {
            $xml .= $this->atomEntry($itemData);
        }

        $xml .= '</feed>';

        return $xml;
    }

    /**
     * Generate JSON feed
     *
     * @param  array<string, mixed>  $feedData
     */
    public function toJson(array $feedData, ?string $callback = null): string
    {
        $jsonFeed = [
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => $feedData['title'] ?? 'JSON Feed',
            'home_page_url' => $feedData['link'] ?? '',
            'feed_url' => $feedData['feed_url'] ?? '',
            'items' => [],
        ];

        if (! empty($feedData['description'])) {
            $jsonFeed['description'] = $feedData['description'];
        }

        /** @var array<int, array<string, mixed>> $feedItems */
        $feedItems = $feedData['items'] ?? [];
        foreach ($feedItems as $itemData) {
            $jsonFeed['items'][] = $this->jsonFeedItem($itemData);
        }

        $json = json_encode($jsonFeed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('JSON encoding failed');
        }

        if ($callback !== null) {
            return $callback . '(' . $json . ');';
        }

        return $json;
    }

    /**
     * Generate RSS item
     *
     * @param  array<string, mixed>  $data
     */
    private function rssItem(array $data): string
    {
        $xml = '<item>'.PHP_EOL;
        
        $xml .= $this->cdataElement('title', $this->stripTags($data['title'] ?? 'Untitled'));
        $xml .= $this->element('link', $data['link'] ?? '');
        $xml .= $this->element('guid', $data['effective_url'] ?? ($data['link'] ?? ''));
        $xml .= $this->element('pubDate', $this->formatRssDate($data['date'] ?? null));
        
        if (! empty($data['author'])) {
            $xml .= $this->cdataElement('dc:creator', is_string($data['author']) ? $data['author'] : (string) $data['author']);
        }
        
        if (! empty($data['language'])) {
            $xml .= $this->element('dc:language', $data['language']);
        }

        // Description (excerpt or content)
        $description = $data['description'] ?? '';
        $xml .= $this->cdataElement('description', $this->stripTags($description));

        // Full content
        if (! empty($description)) {
            $xml .= $this->cdataElement('content:encoded', $description);
        }

        // Categories
        if (! empty($data['categories']) && is_array($data['categories'])) {
            foreach ($data['categories'] as $category) {
                $xml .= $this->cdataElement('category', (string) $category);
            }
        }

        // Enclosure (media)
        if (! empty($data['image'])) {
            $xml .= $this->enclosureElement($data['image']);
        }

        $xml .= '</item>'.PHP_EOL;

        return $xml;
    }

    /**
     * Generate Atom entry
     *
     * @param  array<string, mixed>  $data
     */
    private function atomEntry(array $data): string
    {
        $xml = '<entry>'.PHP_EOL;
        
        $xml .= $this->cdataElement('title', $this->stripTags($data['title'] ?? 'Untitled'));
        $xml .= $this->element('link', '', ['href' => $data['link'] ?? '']);
        $xml .= $this->element('id', $data['effective_url'] ?? ($data['link'] ?? 'urn:uuid:' . $this->generateUuid()));
        $xml .= $this->element('updated', $this->formatAtomDate($data['date'] ?? time()));
        
        if (! empty($data['author'])) {
            $xml .= '<author>'.PHP_EOL;
            $xml .= $this->element('name', is_string($data['author']) ? $data['author'] : (string) $data['author']);
            $xml .= '</author>'.PHP_EOL;
        }

        // Summary
        $description = $data['description'] ?? '';
        $xml .= $this->cdataElement('summary', $this->stripTags($description));

        // Full content
        if (! empty($description)) {
            $xml .= $this->cdataElement('content', $description, ['type' => 'html']);
        }

        // Categories
        if (! empty($data['categories']) && is_array($data['categories'])) {
            foreach ($data['categories'] as $category) {
                $xml .= $this->element('category', '', ['term' => (string) $category]);
            }
        }

        $xml .= '</entry>'.PHP_EOL;

        return $xml;
    }

    /**
     * Generate JSON feed item
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function jsonFeedItem(array $data): array
    {
        $item = [
            'id' => $data['effective_url'] ?? ($data['link'] ?? ''),
            'url' => $data['link'] ?? '',
            'title' => $this->stripTags($data['title'] ?? 'Untitled'),
            'content_html' => $data['description'] ?? '',
            'date_published' => $this->formatJsonDate($data['date'] ?? null),
        ];

        if (! empty($data['summary'])) {
            $item['summary'] = $data['summary'];
        }

        if (! empty($data['author'])) {
            $item['author'] = [
                'name' => is_string($data['author']) ? $data['author'] : (string) $data['author'],
            ];
        }

        if (! empty($data['image'])) {
            $item['image'] = $data['image'];
        }

        if (! empty($data['categories']) && is_array($data['categories'])) {
            $item['tags'] = array_map('strval', $data['categories']);
        }

        return $item;
    }

    /**
     * Create XML element
     */
    private function element(string $name, string $value, array $attributes = []): string
    {
        $attrs = '';
        foreach ($attributes as $key => $val) {
            $attrs .= ' ' . $key . '="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
        }

        if (empty($value) && empty($attributes)) {
            return '<' . $name . '/>' . PHP_EOL;
        }

        return '<' . $name . $attrs . '>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</' . $name . '>' . PHP_EOL;
    }

    /**
     * Create CDATA XML element
     */
    private function cdataElement(string $name, string $value, array $attributes = []): string
    {
        $attrs = '';
        foreach ($attributes as $key => $val) {
            $attrs .= ' ' . $key . '="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
        }

        return '<' . $name . $attrs . '><![CDATA[' . $value . ']]></' . $name . '>' . PHP_EOL;
    }

    /**
     * Create enclosure element
     */
    private function enclosureElement(string $url): string
    {
        $type = $this->getMimeType($url);
        
        // Note: We don't know the length without fetching the file
        // Many RSS readers are okay without the length attribute
        return '<enclosure url="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" type="' . $type . '"/>' . PHP_EOL;
    }

    /**
     * Get MIME type from URL
     */
    private function getMimeType(string $url): string
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        
        $types = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'pdf' => 'application/pdf',
        ];

        return $types[$extension] ?? 'application/octet-stream';
    }

    /**
     * Format date for RSS
     */
    private function formatRssDate($date): string
    {
        if ($date === null) {
            return date('r');
        }

        if ($date instanceof DateTime || $date instanceof DateTimeImmutable) {
            return $date->format('r');
        }

        if (is_int($date)) {
            return date('r', $date);
        }

        $timestamp = strtotime((string) $date);
        if ($timestamp === false) {
            return date('r');
        }

        return date('r', $timestamp);
    }

    /**
     * Format date for Atom
     */
    private function formatAtomDate($date): string
    {
        if ($date === null) {
            return date('c');
        }

        if ($date instanceof DateTime || $date instanceof DateTimeImmutable) {
            return $date->format('c');
        }

        if (is_int($date)) {
            return date('c', $date);
        }

        $timestamp = strtotime((string) $date);
        if ($timestamp === false) {
            return date('c');
        }

        return date('c', $timestamp);
    }

    /**
     * Format date for JSON
     */
    private function formatJsonDate($date): ?string
    {
        if ($date === null) {
            return date('c');
        }

        if ($date instanceof DateTime || $date instanceof DateTimeImmutable) {
            return $date->format('c');
        }

        if (is_int($date)) {
            return date('c', $date);
        }

        $timestamp = strtotime((string) $date);
        if ($timestamp === false) {
            return null;
        }

        return date('c', $timestamp);
    }

    /**
     * Strip HTML tags from text
     */
    private function stripTags(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        return strip_tags($text);
    }

    /**
     * Generate UUID
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
