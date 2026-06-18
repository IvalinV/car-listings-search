<?php

use App\Services\Scrapers\CarsBgScraper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * cars.bg /offer/{id} ids are MongoDB ObjectIDs whose first 4 bytes encode the
 * document-creation Unix timestamp. That is the original publication instant
 * and stays fixed across bumps, unlike the page's "днес"/"d.m.y" text which
 * only reflects the latest refresh. These tests pin that decoding down.
 */
it('decodes the original publish date from an offer ObjectID without an extra request', function (string $link, string $expected): void {
    Http::preventStrayRequests();

    $published = (new CarsBgScraper)->getPublishedDate($link);

    expect($published)->toBeInstanceOf(Carbon::class)
        ->and($published->toDateTimeString())->toBe($expected);
})->with([
    'absolute url' => ['https://www.cars.bg/offer/690b297f56fff2028e0ac604', '2025-11-05 10:39:59'],
    'another id' => ['https://www.cars.bg/offer/6a2bce8c16e02791370e2cc2', '2026-06-12 09:17:00'],
]);

it('returns null when the offer id is missing or decodes to an implausible date', function (?string $link): void {
    expect((new CarsBgScraper)->getPublishedDate($link))->toBeNull();
})->with([
    'null link' => [null],
    'no offer id in link' => ['https://www.cars.bg/carslist.php?page=1'],
    'id too short for an ObjectID' => ['https://www.cars.bg/offer/690b297f'],
    'timestamp before 2010' => ['https://www.cars.bg/offer/000000000000000000000000'],
    'timestamp far in the future' => ['https://www.cars.bg/offer/ffffffff0000000000000000'],
]);

it('sets published_at from the ObjectID on every parsed listing', function (): void {
    $body = file_get_contents(base_path('tests/Fixtures/scrapers/cars_bg.html'));
    Http::fake(['*' => Http::response($body)]);

    $results = (new CarsBgScraper)->scrape(1);

    expect($results)->not->toBeEmpty();

    foreach ($results as $result) {
        expect($result)->toHaveKey('published_at')
            ->and($result['published_at'])->toBeInstanceOf(Carbon::class);
    }

    expect($results[0]['link'])->toContain('offer/690b297f56fff2028e0ac604')
        ->and($results[0]['published_at']->toDateTimeString())->toBe('2025-11-05 10:39:59');
});

it('parses the bumped date from the card subtitle as the update date', function (): void {
    $body = file_get_contents(base_path('tests/Fixtures/scrapers/cars_bg.html'));
    Http::fake(['*' => Http::response($body)]);

    $first = (new CarsBgScraper)->scrape(1)[0];

    // Subtitle reads "днес, 12:32" -> today at that time, the latest refresh.
    expect($first)->toHaveKey('updated_at')
        ->and($first['updated_at'])->toBeInstanceOf(Carbon::class)
        ->and($first['updated_at']->toTimeString())->toBe('12:32:00')
        ->and($first['updated_at']->isToday())->toBeTrue();
});

/**
 * cars.bg soft-deletes: a removed listing 302s to status_page.php (never a
 * 404), while a live listing stays 200 on its /offer/ URL.
 */
it('flags a cars.bg listing as removed when it redirects to status_page.php', function (): void {
    Http::fake(['www.cars.bg/*' => Http::response('', 302, ['Location' => 'https://www.cars.bg/status_page.php?m=expired_job_err'])]);

    expect((new CarsBgScraper)->isListingRemoved('https://www.cars.bg/offer/000000000000000000000000'))->toBeTrue();
});

it('treats a live cars.bg listing (200) as not removed', function (): void {
    Http::fake(['www.cars.bg/*' => Http::response('<html>offer</html>', 200)]);

    expect((new CarsBgScraper)->isListingRemoved('https://www.cars.bg/offer/6874dd52a39c9e28ac01ec57'))->toBeFalse();
});

it('treats a cars.bg redirect that is not to status_page.php as not removed', function (): void {
    Http::fake(['www.cars.bg/*' => Http::response('', 302, ['Location' => 'https://www.cars.bg/'])]);

    expect((new CarsBgScraper)->isListingRemoved('https://www.cars.bg/offer/123'))->toBeFalse();
});
