<?php

use App\Services\Scrapers\AutoBgScraper;
use App\Services\Scrapers\Car24Scraper;
use App\Services\Scrapers\CarsBgScraper;
use App\Services\Scrapers\MobileBgScraper;
use App\Services\Scrapers\Scraper;
use Illuminate\Support\Facades\Http;

/**
 * Stub every outbound HTTP call with a frozen copy of the source's listing
 * page, captured under tests/Fixtures/scrapers. These guard against the
 * scraped sites changing their markup (as auto.bg did) without us noticing.
 */
function fakeScraperFixture(string $fixture): void
{
    $body = file_get_contents(base_path("tests/Fixtures/scrapers/$fixture"));

    Http::fake(['*' => Http::response($body)]);
}

it('parses listings from the current page layout', function (string $scraperClass, string $fixture, string $source, string $linkContains): void {
    fakeScraperFixture($fixture);

    /** @var Scraper $scraper */
    $scraper = new $scraperClass;
    $results = $scraper->scrape(1);

    expect($results)->toBeArray()
        ->and(count($results))->toBeGreaterThanOrEqual(15);

    $first = $results[0];

    expect($first)->toHaveKeys(['title', 'price', 'link', 'description', 'image', 'location', 'source', 'params'])
        ->and($first['title'])->not->toBeEmpty()
        ->and($first['price'])->not->toBeEmpty()
        ->and($first['link'])->toContain($linkContains)
        ->and($first['source'])->toBe($source)
        ->and($first['params'])->toBeArray()
        ->and($first['params'])->toHaveKeys(['production_year', 'mileage', 'fuel'])
        ->and($first['params']['production_year'])->toBeInt()->toBeGreaterThan(1900);
})->with([
    'auto.bg' => [AutoBgScraper::class, 'auto_bg.json', 'auto.bg', 'auto.bg'],
    'cars.bg' => [CarsBgScraper::class, 'cars_bg.html', 'cars.bg', 'cars.bg'],
    'mobile.bg' => [MobileBgScraper::class, 'mobile_bg.html', 'mobile.bg', 'mobile.bg'],
    'car24.bg' => [Car24Scraper::class, 'car24.json', 'car24.bg', 'car24.bg'],
]);

it('builds a clean auto.bg description from spec pills, location and date', function (): void {
    fakeScraperFixture('auto_bg.json');

    $first = (new AutoBgScraper)->scrape(1)[0];

    expect($first['description'])
        ->toContain(' · ')
        ->toContain($first['location'])
        ->toMatch('/\d{4} г\./u')          // production year pill
        ->toMatch('/\d[\d\s]* км\./u')      // mileage pill
        ->not->toContain('€')               // price must not leak into the description
        ->not->toContain('ДДС');            // VAT note must not leak in
});

it('returns an empty result set when the source responds with an error', function (string $scraperClass): void {
    Http::fake(['*' => Http::response('', 500)]);

    expect((new $scraperClass)->scrape(1))->toBe([]);
})->with([
    'auto.bg' => [AutoBgScraper::class],
    'cars.bg' => [CarsBgScraper::class],
    'mobile.bg' => [MobileBgScraper::class],
]);
