<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Services;

class UrlResolver
{
    public function resolveUrl(?string $url, ?string $baseUrl): ?string
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

        return $this->makeAbsolute($baseUrl, $url);
    }

    public function makeAbsolute(string $base, string $url): ?string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if ($this->startsWith($url, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme.':'.$url;
        }

        if ($this->startsWith($url, 'data:')) {
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

        if ($this->startsWith($url, '/')) {
            return $scheme.'://'.$host.$url;
        }

        return $scheme.'://'.$host.$baseDir.'/'.$url;
    }

    private function startsWith(string $haystack, string $needle): bool
    {
        return $needle === '' || str_starts_with($haystack, $needle);
    }
}
