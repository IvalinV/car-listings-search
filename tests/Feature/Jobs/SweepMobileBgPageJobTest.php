<?php

use App\Jobs\SweepMobileBgPageJob;
use App\Models\CarListing;
use App\Services\ListingPersister;
use App\Services\Scrapers\MobileBgScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function pageCard(string $id): string
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

function pageHtml(string ...$ids): string
{
    $html = '<html><body><div class="ads2023">'.implode('', array_map('pageCard', $ids)).'</div></div></body></html>';

    // MobileBgScraper declares segment responses as windows-1251; the fixture
    // must be encoded that way too so the Cyrillic bytes in `.params` parse.
    return mb_convert_encoding($html, 'Windows-1251', 'UTF-8');
}

function emptyPageHtml(): string
{
    return '<html><body><div class="ads2023"></div></body></html>';
}

it('persists a full page and chains to the next page carrying its child slugs', function (): void {
    config()->set('listings.mobilebg_sweep.page_cap', 150);
    Bus::fake();

    Http::fake([
        'www.mobile.bg/obiavi/avtomobili-dzhipove/bmw' => Http::response(pageHtml('11779353449257335')),
    ]);

    (new SweepMobileBgPageJob('bmw', 1, ['116', 'x5']))->handle(new MobileBgScraper, app(ListingPersister::class));

    expect(CarListing::count())->toBe(1);
    Bus::assertDispatchedTimes(SweepMobileBgPageJob::class, 1);
    Bus::assertDispatched(
        SweepMobileBgPageJob::class,
        fn ($job) => $job->slug === 'bmw' && $job->page === 2 && $job->childSlugs === ['116', 'x5'],
    );
});

it('stops and dispatches nothing on an empty page', function (): void {
    config()->set('listings.mobilebg_sweep.page_cap', 150);
    Bus::fake();

    Http::fake([
        'www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/p-4' => Http::response(emptyPageHtml()),
    ]);

    (new SweepMobileBgPageJob('bmw', 4, ['116', 'x5']))->handle(new MobileBgScraper, app(ListingPersister::class));

    expect(CarListing::count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('descends into one child model walker per model when it reaches the page cap', function (): void {
    config()->set('listings.mobilebg_sweep.page_cap', 3);
    Bus::fake();

    Http::fake([
        'www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/p-3' => Http::response(pageHtml('11779353449257335')),
    ]);

    (new SweepMobileBgPageJob('bmw', 3, ['116', 'x5']))->handle(new MobileBgScraper, app(ListingPersister::class));

    expect(CarListing::count())->toBe(1);
    Bus::assertDispatchedTimes(SweepMobileBgPageJob::class, 2);
    Bus::assertDispatched(
        SweepMobileBgPageJob::class,
        fn ($job) => $job->slug === 'bmw/116' && $job->page === 1 && $job->childSlugs === [],
    );
    Bus::assertDispatched(
        SweepMobileBgPageJob::class,
        fn ($job) => $job->slug === 'bmw/x5' && $job->page === 1 && $job->childSlugs === [],
    );
});

it('logs a truncation warning and dispatches nothing at the cap with no child slugs', function (): void {
    config()->set('listings.mobilebg_sweep.page_cap', 3);
    Bus::fake();
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message) => str_contains($message, 'bmw/x5') && str_contains($message, 'page cap'),
    );

    Http::fake([
        'www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/x5/p-3' => Http::response(pageHtml('11779353449257335')),
    ]);

    (new SweepMobileBgPageJob('bmw/x5', 3))->handle(new MobileBgScraper, app(ListingPersister::class));

    Bus::assertNothingDispatched();
});

it('guards against overlapping runs of the same page', function (): void {
    $middleware = (new SweepMobileBgPageJob('bmw/320', 2))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('mbg:bmw/320:2');
});
