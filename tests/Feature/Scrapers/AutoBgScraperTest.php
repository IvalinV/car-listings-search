<?php

use App\Services\Scrapers\AutoBgScraper;
use Carbon\Carbon;
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
