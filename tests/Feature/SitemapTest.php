<?php

use App\Jobs\ScrapeListingJob;
use App\Models\CarListing;
use App\Services\Scrapers\CarsBgScraper;
use App\Services\SitemapCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

it('serves a sitemap index pointing at paginated child sitemaps', function (): void {
    CarListing::factory()->create();

    get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee('<sitemapindex', false)
        ->assertSee(route('sitemap.page', ['page' => 1]), false);
});

it('serves a child sitemap with the homepage and only active listings', function (): void {
    $active = CarListing::factory()->create();
    $inactive = CarListing::factory()->inactive()->create();

    get(route('sitemap.page', ['page' => 1]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee('<urlset', false)
        ->assertSee(route('car-listings'), false)
        ->assertSee(route('car-detail', $active), false)
        ->assertDontSee(route('car-detail', $inactive), false);
});

it('404s for a child sitemap page beyond the available range', function (): void {
    CarListing::factory()->create();

    get(route('sitemap.page', ['page' => 99]))->assertNotFound();
});

it('caches child sitemap output and serves fresh content after a flush', function (): void {
    $first = CarListing::factory()->create();

    get(route('sitemap.page', ['page' => 1]))->assertSee(route('car-detail', $first), false);

    $second = CarListing::factory()->create();

    // Still cached — the new listing is not visible yet.
    get(route('sitemap.page', ['page' => 1]))->assertDontSee(route('car-detail', $second), false);

    SitemapCache::flush();

    // After invalidation the sitemap is regenerated.
    get(route('sitemap.page', ['page' => 1]))->assertSee(route('car-detail', $second), false);
});

it('flushes the sitemap cache when the scrape job persists records', function (): void {
    get(route('sitemap.page', ['page' => 1]))->assertOk(); // warm the cache
    expect((int) Cache::get('sitemap.version', 1))->toBe(1);

    $record = [
        'title' => 'Scraped Test Car',
        'price' => '5 000 EUR',
        'link' => 'https://www.cars.bg/offer/test',
        'description' => 'desc',
        'image' => 'https://example.com/x.jpg',
        'location' => 'София',
        'source' => 'cars.bg',
        'params' => ['production_year' => 2018, 'mileage' => 1000, 'fuel' => 'Diesel', 'transmission' => 'Automatic'],
    ];

    (new ScrapeListingJob(CarsBgScraper::class, 1, 1))->persistRecords([$record], new CarsBgScraper);

    expect((int) Cache::get('sitemap.version', 1))->toBeGreaterThan(1);
});

it('serves robots.txt that references the sitemap', function (): void {
    get('/robots.txt')
        ->assertOk()
        ->assertSee('User-agent: *', false)
        ->assertSee('Sitemap: '.url('/sitemap.xml'), false);
});

it('points the listings canonical at the clean URL, dropping filter params', function (): void {
    get('/?q=bmw&fuelType=Diesel&minPrice=5000')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.route('car-listings').'"', false);
});
