<?php

use App\Models\CarListing;
use App\Services\ListingPersister;
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
