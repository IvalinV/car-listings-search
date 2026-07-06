<?php

use App\Services\Scrapers\AutoBgScraper;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    // Freeze "now" so the relative "днес" date is deterministic.
    Carbon::setTestNow(Carbon::parse('2026-06-14 09:00:00', 'UTC'));
});

it('parses auto.bg dates into a UTC instant', function (string $input, string $expected): void {
    expect((new AutoBgScraper)->getPublishedDate($input)?->toDateTimeString())->toBe($expected);
})->with([
    // Stored verbatim (no timezone conversion); "днес" resolves to today's date.
    'relative днес' => ['12:55 часа от днес', '2026-06-14 12:55:00'],
    'absolute date' => ['23:50 часа от 13.06.2026', '2026-06-13 23:50:00'],
]);

it('returns null for missing or malformed auto.bg dates', function (?string $input): void {
    expect((new AutoBgScraper)->getPublishedDate($input))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'unparseable' => ['hello world'],
]);

/**
 * auto.bg soft-deletes: a removed listing 301s to its brand/model category
 * page, a never-existed ID returns a genuine 404, and a live listing stays 200.
 */
it('flags an auto.bg listing as removed when it redirects to a category page', function (): void {
    Http::fake(['www.auto.bg/*' => Http::response('', 301, ['Location' => 'https://www.auto.bg/obiavi/jeep/compass'])]);

    expect((new AutoBgScraper)->isListingRemoved('https://www.auto.bg/obiava/123/jeep-compass'))->toBeTrue();
});

it('flags an auto.bg listing as removed when the ID never existed (404)', function (): void {
    Http::fake(['www.auto.bg/*' => Http::response('', 404)]);

    expect((new AutoBgScraper)->isListingRemoved('https://www.auto.bg/obiava/99999999/x'))->toBeTrue();
});

it('treats a live auto.bg listing (200) as not removed', function (): void {
    Http::fake(['www.auto.bg/*' => Http::response('<html>listing</html>', 200)]);

    expect((new AutoBgScraper)->isListingRemoved('https://www.auto.bg/obiava/123/x'))->toBeFalse();
});

it('treats an auto.bg redirect that is not to a category page as not removed', function (): void {
    Http::fake(['www.auto.bg/*' => Http::response('', 301, ['Location' => 'https://www.auto.bg/'])]);

    expect((new AutoBgScraper)->isListingRemoved('https://www.auto.bg/obiava/123/x'))->toBeFalse();
});

it('scrapes listings from the auto.bg JSON API', function (): void {
    Http::fake([
        'www.auto.bg/api/srcresults/*' => Http::response([
            'status' => 'ok',
            'data' => [
                'lastpage' => 100,
                'adverts' => [
                    [
                        'ida' => '21771859131150607',
                        'seo_id' => '54125031',
                        'url' => '/obiava/54125031/audi-q5-3-0tdi-239-k-s-quattro',
                        'title' => 'Audi Q5 3.0TDI 239 к.с. quattro',
                        'price' => '6 300 EUR',
                        'price2' => '12 321,73 лв.',
                        'year' => '2008',
                        'month' => 'декември',
                        'km' => ' 270 000',
                        'engine_type' => 'Дизел',
                        'locat' => 'Пловдив',
                        'pict' => '//mobistatic2.focus.bg/mobile/photosorg/607/2/21771859131150607_kQ.webp',
                        'pubtime' => '10:10 часа от днес',
                        'active' => 1,
                    ],
                ],
            ],
        ]),
    ]);

    $results = (new AutoBgScraper)->scrape(page: 1);

    expect($results)->toHaveCount(1)
        ->and($results[0]['title'])->toBe('Audi Q5 3.0TDI 239 к.с. quattro')
        ->and($results[0]['link'])->toBe('https://www.auto.bg/obiava/54125031/audi-q5-3-0tdi-239-k-s-quattro')
        ->and($results[0]['image'])->toBe('https://mobistatic2.focus.bg/mobile/photosorg/607/2/21771859131150607_kQ.webp')
        ->and($results[0]['location'])->toBe('Пловдив')
        ->and($results[0]['source'])->toBe('auto.bg')
        ->and($results[0]['published_at']?->toDateTimeString())->toBe('2026-06-14 10:10:00');

    // Price string is parseable by the shared extractPrice()/extractListingParams().
    $scraper = new AutoBgScraper;
    expect($scraper->extractPrice($results[0]['price'])['eur'])->toBe(6300.0)
        ->and($results[0]['params']['production_year'])->toBe(2008)
        ->and($results[0]['params']['mileage'])->toBe(270000)
        ->and($results[0]['params']['fuel'])->toBe('Diesel');
});

it('returns an empty array when the auto.bg API request fails', function (): void {
    Http::fake(['www.auto.bg/api/srcresults/*' => Http::response('', 500)]);

    expect((new AutoBgScraper)->scrape(page: 1))->toBe([]);
});

it('scrapes auto.bg makes from the category page links', function (): void {
    $html = '<html><body>'
        .'<a href="/obiavi/avtomobili-dzhipove/audi">Audi</a>'
        .'<a href="/obiavi/avtomobili-dzhipove/bmw">BMW</a>'
        .'<a href="/obiavi/avtomobili-dzhipove/alfa-romeo">Alfa Romeo</a>'
        .'<a href="/obiavi/avtomobili-dzhipove/bmw/320">320</a>'
        .'<a href="/obiavi/avtomobili-dzhipove/bmw/page/2">2</a>'
        .'</body></html>';
    Http::fake(['www.auto.bg/*' => Http::response($html)]);

    $makes = (new AutoBgScraper)->scrapeMakes();

    expect($makes)->toEqual([
        ['name' => 'Audi', 'slug' => 'audi'],
        ['name' => 'BMW', 'slug' => 'bmw'],
        ['name' => 'Alfa Romeo', 'slug' => 'alfa-romeo'],
    ]);
});

it('scrapes auto.bg models for a make, skipping pagination and duplicates', function (): void {
    $html = '<html><body>'
        .'<a href="/obiavi/avtomobili-dzhipove/bmw/320">320</a>'
        .'<a href="/obiavi/avtomobili-dzhipove/bmw/x5">X5</a>'
        .'<a href="/obiavi/avtomobili-dzhipove/bmw/320">320</a>'
        .'<a href="/obiavi/avtomobili-dzhipove/bmw/page/2">next</a>'
        .'</body></html>';
    Http::fake(['www.auto.bg/*' => Http::response($html)]);

    $models = (new AutoBgScraper)->scrapeModels('bmw');

    expect($models)->toEqual([
        ['name' => '320', 'slug' => '320'],
        ['name' => 'X5', 'slug' => 'x5'],
    ]);
});

it('fetches a page of active seo_ids with the reported last page', function (): void {
    Http::fake([
        'www.auto.bg/api/srcresults/*' => Http::response([
            'data' => [
                'lastpage' => 7,
                'adverts' => [
                    ['seo_id' => '111', 'active' => 1],
                    ['seo_id' => '222', 'active' => 0],
                    ['seo_id' => '333', 'active' => 1],
                ],
            ],
        ]),
    ]);

    $result = (new AutoBgScraper)->fetchAdvertPage('avtomobili-dzhipove/audi', 1);

    expect($result['ok'])->toBeTrue()
        ->and($result['lastpage'])->toBe(7)
        ->and($result['ids'])->toBe(['111', '333']); // inactive 222 skipped
});

it('reports ok=false when the advert page request fails', function (): void {
    Http::fake(['www.auto.bg/api/srcresults/*' => Http::response('', 500)]);

    $result = (new AutoBgScraper)->fetchAdvertPage('avtomobili-dzhipove/audi', 1);

    expect($result)->toBe(['ids' => [], 'lastpage' => 0, 'ok' => false]);
});

it('reports ok=false when the advert page connection fails', function (): void {
    Http::fake(['www.auto.bg/api/srcresults/*' => fn () => throw new ConnectionException('timed out')]);

    expect((new AutoBgScraper)->fetchAdvertPage('avtomobili-dzhipove/audi', 1)['ok'])->toBeFalse();
});
