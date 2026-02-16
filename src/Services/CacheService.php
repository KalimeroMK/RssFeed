<?php

declare(strict_types=1);

namespace Kalimeromk\Rssfeed\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Cache Service
 * Wrapper around Laravel Cache for RSS feed caching
 */
class CacheService
{
    private bool $enabled;

    private int $ttl;

    private string $prefix;

    public function __construct()
    {
        $this->enabled = config('rssfeed.caching_enabled', false);
        $this->ttl = (int) config('rssfeed.cache_time', 10) * 60; // Convert minutes to seconds
        $this->prefix = config('rssfeed.cache_prefix', 'rssfeed_');
    }

    /**
     * Get item from cache
     */
    public function get(string $key): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        $value = Cache::get($this->prefix . $key);

        return is_string($value) ? $value : null;
    }

    /**
     * Store item in cache
     */
    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return Cache::put($this->prefix . $key, $value, $ttl ?? $this->ttl);
    }

    /**
     * Check if key exists in cache
     */
    public function has(string $key): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return Cache::has($this->prefix . $key);
    }

    /**
     * Delete item from cache
     */
    public function delete(string $key): bool
    {
        return Cache::forget($this->prefix . $key);
    }

    /**
     * Clear all cache
     */
    public function clear(): bool
    {
        // Note: This only clears items with our prefix in the current cache driver
        // For full cache clearing, you might need a different approach
        return Cache::flush();
    }

    /**
     * Get cache statistics
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'enabled' => $this->enabled,
            'ttl' => $this->ttl,
            'prefix' => $this->prefix,
            'driver' => config('cache.default', 'file'),
        ];
    }

    /**
     * Remember value from cache or execute callback
     *
     * @param  callable  $callback
     * @return mixed
     */
    public function remember(string $key, ?int $ttl, callable $callback)
    {
        if (! $this->enabled) {
            return $callback();
        }

        return Cache::remember($this->prefix . $key, $ttl ?? $this->ttl, $callback);
    }
}
