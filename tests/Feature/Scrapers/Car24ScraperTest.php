<?php

use App\Services\Scrapers\Car24Scraper;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('parses the car24.bg pubtime as written', function (): void {
    // Stored verbatim, no timezone conversion.
    expect((new Car24Scraper)->getPublishedDate('12:30 на 12.06.2026')?->toDateTimeString())
        ->toBe('2026-06-12 12:30:00');
});

it('returns null instead of throwing on missing or malformed pubtime', function (?string $input): void {
    expect((new Car24Scraper)->getPublishedDate($input))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'unparseable' => ['not a date'],
]);

/**
 * car24.bg soft-deletes via its public pages, so removal is read from the
 * mobile API: an advert payload means live, its absence means removed. A
 * transient API failure must NOT be mistaken for a removed listing.
 */
it('treats a car24 listing with an advert payload as live', function (): void {
    Http::fake(['api.car24.bg/*' => Http::response(['status' => 'ok', 'data' => ['advert' => ['id' => 123]]])]);

    expect((new Car24Scraper)->isListingRemoved('https://car24.bg/obiava/123/mazda-cx-5'))->toBeFalse();
});

it('flags a car24 listing as removed when the API returns no advert', function (): void {
    Http::fake(['api.car24.bg/*' => Http::response(['status' => 'error', 'status_code' => '301', 'data' => ['error' => ['newUrl' => '/obiavi/mazda/cx-5']]])]);

    expect((new Car24Scraper)->isListingRemoved('https://car24.bg/obiava/123/mazda-cx-5'))->toBeTrue();
});

it('flags a car24 listing as removed on a 404 from the API', function (): void {
    Http::fake(['api.car24.bg/*' => Http::response('', 404)]);

    expect((new Car24Scraper)->isListingRemoved('https://car24.bg/obiava/99999999/x'))->toBeTrue();
});

it('does not mistake a transient car24 API failure for a removed listing', function (): void {
    Http::fake(['api.car24.bg/*' => Http::response('', 500)]);

    expect(fn (): bool => (new Car24Scraper)->isListingRemoved('https://car24.bg/obiava/123/x'))
        ->toThrow(RequestException::class);
});

it('scrapes and merges car24 popular and other makes', function (): void {
    Http::fake(['api.car24.bg/*' => Http::response([
        'status' => 'success',
        'data' => [
            'marki' => [['Audi', 'audi'], ['BMW', 'bmw'], ['VW', 'vw']],
            'markiOther' => [
                ['brand' => 'Abarth', 'count' => '27', 'sef' => 'abarth'],
                ['brand' => 'Acura', 'count' => '46', 'sef' => 'acura'],
            ],
        ],
    ])]);

    $makes = (new Car24Scraper)->scrapeMakes();

    expect($makes)->toContain(['name' => 'VW', 'slug' => 'vw'])
        ->and($makes)->toContain(['name' => 'Abarth', 'slug' => 'abarth'])
        ->and(collect($makes)->pluck('name')->all())->toContain('Audi', 'BMW', 'Acura');
});
