<?php

use App\Services\Scrapers\AutoBgScraper;
use App\Services\Scrapers\Car24Scraper;
use App\Services\Scrapers\CarsBgScraper;
use App\Services\Scrapers\MobileBgScraper;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

/** Run the poolable probe + interpreter pair through a real pool, mirroring the sweep's path. */
function probeRemoved(object $scraper, string $url): bool
{
    $responses = Http::pool(fn (Pool $pool) => [$scraper->poolRemovalProbe($pool->as('probe'), $url)]);

    return $scraper->isRemovedFromResponse($responses['probe'], $url);
}

it('mobile.bg probe: a 404 is removed', function (): void {
    Http::fake(['*mobile.bg*' => Http::response('', 404)]);
    expect(probeRemoved(new MobileBgScraper, 'https://www.mobile.bg/obiava-123-x'))->toBeTrue();
});

it('mobile.bg probe: a 200 is alive', function (): void {
    Http::fake(['*mobile.bg*' => Http::response('<html>live</html>', 200)]);
    expect(probeRemoved(new MobileBgScraper, 'https://www.mobile.bg/obiava-123-x'))->toBeFalse();
});

it('auto.bg probe: a category redirect is removed', function (): void {
    Http::fake(['www.auto.bg/*' => Http::response('', 301, ['Location' => 'https://www.auto.bg/obiavi/jeep/compass'])]);
    expect(probeRemoved(new AutoBgScraper, 'https://www.auto.bg/obiava/123/jeep'))->toBeTrue();
});

it('auto.bg probe: a 200 is alive', function (): void {
    Http::fake(['www.auto.bg/*' => Http::response('<html>live</html>', 200)]);
    expect(probeRemoved(new AutoBgScraper, 'https://www.auto.bg/obiava/123/jeep'))->toBeFalse();
});

it('cars.bg probe: a status_page redirect is removed', function (): void {
    Http::fake(['*cars.bg*' => Http::response('', 302, ['Location' => 'https://www.cars.bg/status_page.php?m=expired_job_err'])]);
    expect(probeRemoved(new CarsBgScraper, 'https://www.cars.bg/offer/abc123'))->toBeTrue();
});

it('cars.bg probe: a 200 is alive', function (): void {
    Http::fake(['*cars.bg*' => Http::response('<html>live</html>', 200)]);
    expect(probeRemoved(new CarsBgScraper, 'https://www.cars.bg/offer/abc123'))->toBeFalse();
});

it('car24 probe: a null advert is removed', function (): void {
    Http::fake(['api.car24.bg/*' => Http::response(['data' => ['advert' => null]], 200)]);
    expect(probeRemoved(new Car24Scraper, 'https://car24.bg/obiava/75188220/x'))->toBeTrue();
});

it('car24 probe: a 404 is removed', function (): void {
    Http::fake(['api.car24.bg/*' => Http::response('', 404)]);
    expect(probeRemoved(new Car24Scraper, 'https://car24.bg/obiava/75188220/x'))->toBeTrue();
});

it('car24 probe: a present advert is alive', function (): void {
    Http::fake(['api.car24.bg/*' => Http::response(['data' => ['advert' => ['id' => 1]]], 200)]);
    expect(probeRemoved(new Car24Scraper, 'https://car24.bg/obiava/75188220/x'))->toBeFalse();
});

it('car24 probe queries the mobile API with ida and title', function (): void {
    Http::fake(['api.car24.bg/*' => Http::response(['data' => ['advert' => ['id' => 1]]], 200)]);

    probeRemoved(new Car24Scraper, 'https://car24.bg/obiava/75188220/chevrolet-captiva');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.car24.bg/mobile_api/adverts/loadbyid')
        && str_contains($request->url(), 'ida=75188220')
        && str_contains($request->url(), 'title=chevrolet-captiva'));
});
