<?php

use App\Jobs\SweepMobileBgSegmentJob;
use App\Models\CarListing;
use App\Services\ListingPersister;
use App\Services\Scrapers\MobileBgScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function segmentCard(string $id): string
{
    // Mileage is varied by the id's last digit so that two otherwise-identical
    // cards (no image, same price/year) don't collapse into one listing via
    // the params-hash dedup fallback (see Deduplication::paramsHash).
    $mileage = 200000 + ((int) substr($id, -1));

    return '<div class="item">'
        .'<div class="zaglavie"><a href="/obiava-'.$id.'-bmw-320">x</a></div>'
        .'<div class="title">BMW 320</div><div class="price">1 000 EUR</div>'
        .'<div class="info">Diesel</div><div class="location">Sofia</div>'
        .'<div class="params">2012 г. '.number_format($mileage, 0, '.', ' ').' км</div></div>';
}

function segmentPage(string ...$ids): string
{
    $html = '<html><body><div class="ads2023">'.implode('', array_map('segmentCard', $ids)).'</div></div></body></html>';

    // MobileBgScraper declares segment responses as windows-1251 (see
    // MobileBgScraperTest); the fixture must be encoded that way too so the
    // Cyrillic bytes in `.params` (e.g. "г.", "км") parse correctly.
    return mb_convert_encoding($html, 'Windows-1251', 'UTF-8');
}

it('persists every page of a segment and stops at the first empty page', function (): void {
    config()->set('listings.mobilebg_sweep.page_cap', 150);
    config()->set('listings.mobilebg_sweep.pause_ms', 0);
    Bus::fake();

    Http::fake([
        'www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/320' => Http::response(segmentPage('11779353449257335')),
        'www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/320/p-2' => Http::response(segmentPage('11779353449257336')),
        'www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/320/p-3' => Http::response('<html><body><div class="ads2023"></div></body></html>'),
    ]);

    (new SweepMobileBgSegmentJob('bmw/320'))->handle(new MobileBgScraper, app(ListingPersister::class));

    expect(CarListing::count())->toBe(2);
    Bus::assertNothingDispatched();
});

it('descends into child model segments when a make reaches the page cap', function (): void {
    config()->set('listings.mobilebg_sweep.page_cap', 2);
    config()->set('listings.mobilebg_sweep.pause_ms', 0);
    Bus::fake();

    // Both pages full up to the (test) cap of 2 -> treated as truncated.
    Http::fake([
        'www.mobile.bg/obiavi/avtomobili-dzhipove/bmw' => Http::response(segmentPage('11779353449257335')),
        'www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/p-2' => Http::response(segmentPage('11779353449257336')),
    ]);

    (new SweepMobileBgSegmentJob('bmw', ['116', 'x5']))->handle(new MobileBgScraper, app(ListingPersister::class));

    Bus::assertDispatchedTimes(SweepMobileBgSegmentJob::class, 2);
    Bus::assertDispatched(SweepMobileBgSegmentJob::class, fn ($job) => $job->slug === 'bmw/116');
    Bus::assertDispatched(SweepMobileBgSegmentJob::class, fn ($job) => $job->slug === 'bmw/x5');
});
