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

it('scrapes mobile.bg makes from the autocomplete menu', function (): void {
    $html = '<html><body>'
        .'<div class="akSearchMarki" id="akSearchMarki"><div class="scroll">'
        .'<p>-</p>'
        .'<div class="a" data-popular="true"><span>Mercedes-Benz</span> <span>24075</span></div>'
        .'<div class="a"><span>BMW</span> <span>19000</span></div>'
        .'</div></div></body></html>';
    Http::fake(['www.mobile.bg/*' => Http::response($html)]);

    $makes = (new MobileBgScraper)->scrapeMakes();

    expect($makes)->toEqual([
        ['name' => 'Mercedes-Benz', 'slug' => null],
        ['name' => 'BMW', 'slug' => null],
    ]);
});

it('scrapes a make/model segment via the slug URL and parses windows-1251 cards', function (): void {
    $html = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=windows-1251"></head><body>'
        .'<div class="ads2023"><div class="item">'
        .'<div class="zaglavie"><a href="/obiava-11779353449257335-bmw-320">x</a></div>'
        .'<div class="title">БМВ 320</div>'
        .'<div class="price">1 000 EUR</div>'
        .'<div class="info">Дизел, автоматик</div>'
        .'<div class="location">гр. София</div>'
        .'<div class="params">2012 г. 200 000 км Дизел</div>'
        .'</div></div></body></html>';
    $cp1251 = mb_convert_encoding($html, 'Windows-1251', 'UTF-8');
    Http::fake(['www.mobile.bg/*' => Http::response($cp1251)]);

    $results = (new MobileBgScraper)->scrapeSegment('bmw/320', 1);

    expect($results)->toHaveCount(1)
        ->and($results[0]['title'])->toBe('БМВ 320')
        ->and($results[0]['location'])->toBe('гр. София')
        ->and($results[0]['source'])->toBe('mobile.bg')
        ->and($results[0]['link'])->toContain('obiava-11779353449257335');
});

it('builds the /p-N URL for pages beyond the first', function (): void {
    Http::fake(['www.mobile.bg/*' => Http::response('<html></html>')]);

    (new MobileBgScraper)->scrapeSegment('bmw', 3);

    Http::assertSent(fn ($request) => $request->url() === 'https://www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/p-3');
});

it('omits the /p-1 suffix on the first page of a segment', function (): void {
    Http::fake(['www.mobile.bg/*' => Http::response('<html></html>')]);

    (new MobileBgScraper)->scrapeSegment('bmw', 1);

    Http::assertSent(fn ($request) => $request->url() === 'https://www.mobile.bg/obiavi/avtomobili-dzhipove/bmw');
});

it('returns an empty array when a segment page is not successful', function (): void {
    Http::fake(['www.mobile.bg/*' => Http::response('', 404)]);

    expect((new MobileBgScraper)->scrapeSegment('bmw', 5))->toBe([]);
});
