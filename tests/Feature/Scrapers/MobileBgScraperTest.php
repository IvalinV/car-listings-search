<?php

use App\Services\Scrapers\MobileBgScraper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * mobile.bg never prints a publish date on its results page, so the scraper
 * derives one from the listing ID, which embeds the creation Unix timestamp.
 * These tests pin that decoding down and guard against bad input.
 */
it('decodes the publish date from a listing id without an extra request', function (string $link, string $expected): void {
    Http::preventStrayRequests();

    $published = (new MobileBgScraper)->getPublishedDate($link);

    expect($published)->toBeInstanceOf(Carbon::class)
        ->and($published->toDateTimeString())->toBe($expected);
})->with([
    // Both leading type-marker digits (1 and 2) must decode correctly.
    'marker 1' => ['www.mobile.bg/obiava-11781263600736871-mercedes-benz-e-220', '2026-06-12 11:26:40'],
    'marker 2' => ['www.mobile.bg/obiava-21777919237578906-mercedes-benz-c-220', '2026-05-04 18:27:17'],
]);

it('returns null when the id is missing or malformed', function (?string $link): void {
    expect((new MobileBgScraper)->getPublishedDate($link))->toBeNull();
})->with([
    'null link' => [null],
    'no id in link' => ['www.mobile.bg/obiavi/avtomobili-dzhipove'],
    'id too short for a timestamp' => ['www.mobile.bg/obiava-12345-foo'],
    'timestamp before 2010' => ['www.mobile.bg/obiava-10000000001234567-foo'],
    'timestamp far in the future' => ['www.mobile.bg/obiava-19999999991234567-foo'],
]);

it('sets published_at on every parsed listing from the results page', function (): void {
    $body = file_get_contents(base_path('tests/Fixtures/scrapers/mobile_bg.html'));
    Http::fake(['*' => Http::response($body)]);

    $results = (new MobileBgScraper)->scrape(1);

    expect($results)->not->toBeEmpty();

    foreach ($results as $result) {
        expect($result)->toHaveKey('published_at')
            ->and($result['published_at'])->toBeInstanceOf(Carbon::class);
    }

    expect($results[0]['link'])->toContain('obiava-11779353449257335')
        ->and($results[0]['published_at']->toDateTimeString())->toBe('2026-05-21 08:50:49');
});

/**
 * mobile.bg is the only source that returns a genuine 404 for a removed
 * listing, so it relies on the base scraper's default detection.
 */
it('flags a mobile.bg listing as removed on a 404', function (): void {
    Http::fake(['*' => Http::response('', 404)]);

    expect((new MobileBgScraper)->isListingRemoved('https://mobile.bg/obiava-11489507861387492-x'))->toBeTrue();
});

it('treats a live mobile.bg listing (200) as not removed', function (): void {
    Http::fake(['*' => Http::response('<html>listing</html>', 200)]);

    expect((new MobileBgScraper)->isListingRemoved('https://mobile.bg/obiava-11489507861387492-x'))->toBeFalse();
});
