<?php

namespace App\Http\Controllers;

use App\Models\CarListing;
use App\Models\CarMake;
use App\Services\SitemapCache;
use Illuminate\Http\Response;

class SeoController extends Controller
{
    /**
     * URLs per sitemap file. The sitemaps.org limit is 50,000; we stay under it.
     */
    private const URLS_PER_SITEMAP = 40000;

    public function sitemapIndex(): Response
    {
        $xml = SitemapCache::remember('index', function (): string {
            return view('seo.sitemap-index', ['pages' => $this->pageCount()])->render();
        });

        return response($xml)->header('Content-Type', 'application/xml');
    }

    public function sitemapPage(int $page): Response
    {
        abort_if($page < 1 || $page > $this->pageCount(), 404);

        $xml = SitemapCache::remember("page.{$page}", function () use ($page): string {
            $listings = CarListing::query()
                ->active()
                ->select(['id', 'title', 'updated_at'])
                ->orderBy('id')
                ->forPage($page, self::URLS_PER_SITEMAP)
                ->get();

            return view('seo.sitemap', [
                'listings' => $listings,
                'includeHome' => $page === 1,
            ])->render();
        });

        return response($xml)->header('Content-Type', 'application/xml');
    }

    public function sitemapMakes(): Response
    {
        $xml = SitemapCache::remember('makes', function (): string {
            $makeIds = CarListing::query()
                ->where('is_active', true)
                ->whereNotNull('car_make_id')
                ->distinct()
                ->pluck('car_make_id');

            $makes = CarMake::whereIn('id', $makeIds)
                ->orderBy('name')
                ->get(['slug']);

            return view('seo.sitemap-makes', ['makes' => $makes])->render();
        });

        return response($xml)->header('Content-Type', 'application/xml');
    }

    public function robots(): Response
    {
        $body = implode("\n", [
            'User-agent: *',
            'Disallow:',
            '',
            'Sitemap: '.url('/sitemap.xml'),
            '',
        ]);

        return response($body)->header('Content-Type', 'text/plain');
    }

    /**
     * Number of child sitemap files needed for the active listings.
     */
    private function pageCount(): int
    {
        $count = SitemapCache::remember('count', fn (): int => CarListing::query()->active()->count());

        return max(1, (int) ceil($count / self::URLS_PER_SITEMAP));
    }
}
