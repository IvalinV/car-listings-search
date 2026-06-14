<?php

use App\Jobs\ScrapeListingJob;
use App\Models\CarListing;
use App\Services\Scrapers\MobileBgScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Build a scraped-record array the way the scrapers emit one. A null image
 * forces Deduplication to use the param hash, so records sharing mileage/year/
 * fuel merge into one listing without any outbound HTTP.
 */
function scrapedRecord(string $source, string $link, ?Carbon $publishedAt): array
{
    return [
        'title' => 'Audi A4 AVANT',
        'price' => '7295 EUR',
        'link' => $link,
        'description' => 'Audi A4',
        'image' => null,
        'location' => 'София',
        'source' => $source,
        'published_at' => $publishedAt,
        'params' => [
            'production_year' => 2012,
            'mileage' => 201734,
            'fuel' => 'Diesel',
            'transmission' => 'Automatic',
        ],
    ];
}

function persist(array $records): void
{
    (new ScrapeListingJob(MobileBgScraper::class, '1', '1'))
        ->persistRecords($records, new MobileBgScraper);
}

it('merges the same car across sources into one listing with per-source dates', function (): void {
    persist([
        scrapedRecord('mobile.bg', 'www.mobile.bg/obiava-11572426012950088-audi-a4', Carbon::parse('2019-10-30 09:00:12')),
        scrapedRecord('cars.bg', 'www.cars.bg/offer/abc123', Carbon::parse('2026-06-11 09:23:00')),
    ]);

    expect(CarListing::count())->toBe(1);

    $listing = CarListing::first();

    expect($listing->source_dates)->toBe([
        'mobile.bg' => '2019-10-30 09:00:12',
        'cars.bg' => '2026-06-11 09:23:00',
    ])
        // published_at is the MAX across sources, not the last one written.
        ->and($listing->published_at->toDateTimeString())->toBe('2026-06-11 09:23:00')
        ->and($listing->source_urls)->toHaveCount(2);
});

it('keeps published_at at the latest source date when an older source is re-scraped', function (): void {
    persist([scrapedRecord('cars.bg', 'www.cars.bg/offer/abc123', Carbon::parse('2026-06-11 09:23:00'))]);

    expect(CarListing::first()->published_at->toDateTimeString())->toBe('2026-06-11 09:23:00');

    // A later mobile.bg scrape contributes its (much older) creation date.
    persist([scrapedRecord('mobile.bg', 'www.mobile.bg/obiava-11572426012950088-audi-a4', Carbon::parse('2019-10-30 09:00:12'))]);

    $listing = CarListing::first();

    expect(CarListing::count())->toBe(1)
        ->and($listing->published_at->toDateTimeString())->toBe('2026-06-11 09:23:00')
        ->and($listing->source_dates)->toHaveKey('mobile.bg', '2019-10-30 09:00:12');
});

it('leaves published_at null when no source provides a date', function (): void {
    persist([scrapedRecord('cars.bg', 'www.cars.bg/offer/abc123', null)]);

    expect(CarListing::first()->published_at)->toBeNull();
});
