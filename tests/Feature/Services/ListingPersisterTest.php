<?php

use App\Models\CarListing;
use App\Services\ListingPersister;
use App\Services\Scrapers\AutoBgScraper;
use App\Services\Scrapers\Car24Scraper;
use App\Services\Scrapers\MobileBgScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists a scraped record into a car listing', function (): void {
    $record = [
        'title' => 'Audi A4 AVANT',
        'price' => '7295 EUR',
        'link' => 'www.mobile.bg/obiava-11572426012950088-audi-a4',
        'description' => 'Audi A4',
        'image' => null,
        'location' => 'София',
        'source' => 'mobile.bg',
        'published_at' => Carbon::parse('2019-10-30 09:00:12'),
        'params' => ['production_year' => 2012, 'mileage' => 201734, 'fuel' => 'Diesel', 'transmission' => 'Automatic'],
    ];

    app(ListingPersister::class)->persist([$record], new MobileBgScraper);

    $listing = CarListing::sole();
    expect($listing->title)->toBe('Audi A4 AVANT')
        ->and($listing->source_dates)->toBe(['mobile.bg' => ['created' => '2019-10-30 09:00:12']]);
});

it('clamps an out-of-range parsed price to the unpriced sentinel', function (): void {
    $record = [
        'title' => 'VW Amarok 2.0TDI Bi-Turbo',
        'price' => '119999998 EUR',
        'link' => 'www.auto.bg/obiava/50097080/vw-amarok-2-0tdi-bi-turbo',
        'description' => 'VW Amarok',
        'image' => null,
        'location' => 'София',
        'source' => 'auto.bg',
        'published_at' => Carbon::parse('2026-07-16 09:00:00'),
        'params' => ['production_year' => 2024, 'mileage' => 60000, 'fuel' => 'Diesel', 'transmission' => 'Automatic'],
    ];

    app(ListingPersister::class)->persist([$record], new AutoBgScraper);

    expect((float) CarListing::sole()->price)->toBe((float) CarListing::UNPRICED_SENTINEL);
});

it('clamps an out-of-range raw car24 price to the unpriced sentinel', function (): void {
    $record = [
        'title' => 'Kia EV6',
        'price' => 119999998,
        'link' => 'https://car24.bg/obiava/42476640/kia-ev6',
        'description' => 'Kia EV6',
        'image' => null,
        'location' => 'Шумен',
        'source' => 'car24.bg',
        'published_at' => Carbon::parse('2026-07-16 09:02:00'),
        'params' => ['production_year' => 2024, 'mileage' => 60000, 'fuel' => 'Electric', 'transmission' => null],
    ];

    app(ListingPersister::class)->persist([$record], new Car24Scraper);

    expect((float) CarListing::sole()->price)->toBe((float) CarListing::UNPRICED_SENTINEL);
});

it('treats a non-numeric car24 enquiry price as unpriced instead of zero', function (): void {
    $record = [
        'title' => 'Kia EV6',
        'price' => 'enquiry',
        'link' => 'https://car24.bg/obiava/42476641/kia-ev6',
        'description' => 'Kia EV6',
        'image' => null,
        'location' => 'Шумен',
        'source' => 'car24.bg',
        'published_at' => Carbon::parse('2026-07-16 09:02:00'),
        'params' => ['production_year' => 2023, 'mileage' => 50000, 'fuel' => 'Electric', 'transmission' => null],
    ];

    app(ListingPersister::class)->persist([$record], new Car24Scraper);

    expect((float) CarListing::sole()->price)->toBe((float) CarListing::UNPRICED_SENTINEL);
});
