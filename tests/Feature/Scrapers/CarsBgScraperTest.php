<?php

use App\Services\Scrapers\CarsBgScraper;
use Illuminate\Support\Facades\Http;

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
