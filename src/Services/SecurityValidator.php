<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Services;

/**
 * Validates requests for security
 * Handles API keys, URL blocking/allowing
 */
class SecurityValidator
{
    /**
     * Validate API key for request
     *
     * @param  string  $key  The API key to validate
     * @param  string|null  $url  Optional URL for hash-based validation
     * @param  string|null  $hash  Optional hash for validation
     */
    public function validateKey(string $key, ?string $url = null, ?string $hash = null): bool
    {
        $apiKeys = config('rssfeed.api_keys', []);
        $keyRequired = config('rssfeed.key_required', false);

        // If no keys configured and key is not required, allow all
        if (empty($apiKeys) && ! $keyRequired) {
            return true;
        }

        // Check hash-based key
        if ($hash !== null && $url !== null) {
            $keyIndex = (int) $key;
            if (isset($apiKeys[$keyIndex])) {
                $expectedHash = sha1($apiKeys[$keyIndex].$url);

                return $hash === $expectedHash;
            }
        }

        // Check direct key
        if (in_array($key, $apiKeys, true)) {
            return true;
        }

        return ! $keyRequired;
    }

    /**
     * Check if URL is allowed
     */
    public function isUrlAllowed(string $url): bool
    {
        $allowedHosts = config('rssfeed.allowed_hosts', []);
        $blockedHosts = config('rssfeed.blocked_hosts', []);

        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return false;
        }

        // Remove www. prefix for comparison
        $host = strtolower($host);
        $host = preg_replace('/^www\./', '', $host);

        if (! is_string($host)) {
            return false;
        }

        // Check blocked hosts first
        foreach ($blockedHosts as $blocked) {
            if (str_contains($host, strtolower($blocked))) {
                return false;
            }
        }

        // If allowed hosts is empty, allow all (except blocked)
        if (empty($allowedHosts)) {
            return true;
        }

        // Check allowed hosts
        foreach ($allowedHosts as $allowed) {
            if (str_contains($host, strtolower($allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get blocked message
     */
    public function getBlockedMessage(): string
    {
        return config('rssfeed.blocked_message', '<strong>URL blocked</strong>');
    }

    /**
     * Check if service is enabled
     */
    public function isServiceEnabled(): bool
    {
        return config('rssfeed.enabled', true);
    }

    /**
     * Check if API key is required
     */
    public function isKeyRequired(): bool
    {
        return config('rssfeed.key_required', false);
    }

    /**
     * Get maximum entries allowed for a given key
     */
    public function getMaxEntries(?string $key = null): int
    {
        $apiKeys = config('rssfeed.api_keys', []);

        if ($key !== null && in_array($key, $apiKeys, true)) {
            return config('rssfeed.max_entries_with_key', 30);
        }

        return config('rssfeed.max_entries', 10);
    }

    /**
     * Get default entries for a given key
     */
    public function getDefaultEntries(?string $key = null): int
    {
        $apiKeys = config('rssfeed.api_keys', []);

        if ($key !== null && in_array($key, $apiKeys, true)) {
            return config('rssfeed.default_entries_with_key', 5);
        }

        return config('rssfeed.default_entries', 5);
    }
}
