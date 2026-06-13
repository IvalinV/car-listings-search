<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caches rendered sitemap fragments under a versioned key namespace so the
 * whole group can be invalidated with a single version bump — works on any
 * cache store (no cache tags required).
 */
class SitemapCache
{
    public const TTL_SECONDS = 3600;

    private const VERSION_KEY = 'sitemap.version';

    public static function remember(string $suffix, Closure $callback): mixed
    {
        return Cache::remember(self::key($suffix), self::TTL_SECONDS, $callback);
    }

    /**
     * Invalidate every cached sitemap fragment by advancing the namespace version.
     * Old entries are orphaned and expire via their TTL.
     */
    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }

    private static function key(string $suffix): string
    {
        return 'sitemap.v'.self::version().'.'.$suffix;
    }

    private static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }
}
