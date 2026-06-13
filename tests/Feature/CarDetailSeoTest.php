<?php

use App\Models\CarListing;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

it('renders the listing title in the page <title> tag', function (): void {
    $car = CarListing::factory()->create(['title' => 'BMW X5 xDrive40i']);

    get(route('car-detail', $car))
        ->assertOk()
        // The " - AutoSearch" suffix only appears inside <title>, so this proves
        // the page title is set per-listing rather than the default homepage title.
        ->assertSee('BMW X5 xDrive40i - AutoSearch', false)
        ->assertDontSee('Търсене на автомобили - AutoSearch', false);
});

it('outputs Car + Offer JSON-LD structured data for the listing', function (): void {
    $car = CarListing::factory()->create([
        'title' => 'BMW X5 xDrive40i',
        'price' => 23459,
        'year' => 2019,
        'mileage' => 142975,
        'fuel_type' => 'Diesel',
        'transmission' => 'Automatic',
    ]);

    get(route('car-detail', $car))
        ->assertOk()
        ->assertSee('application/ld+json', false)
        ->assertSee('"@type":"Car"', false)
        ->assertSee('"priceCurrency":"EUR"', false)
        ->assertSee('23459', false)
        ->assertSee('"@type":"Offer"', false);
});

it('emits a self-referencing canonical for the listing', function (): void {
    $car = CarListing::factory()->create();

    get(route('car-detail', $car))
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.route('car-detail', $car).'"', false);
});

it('includes a meta description and Open Graph / Twitter tags for the listing', function (): void {
    $car = CarListing::factory()->create([
        'title' => 'BMW X5 xDrive40i',
        'image_url' => 'https://img.example.com/x5.webp',
    ]);

    get(route('car-detail', $car))
        ->assertOk()
        ->assertSee('<meta name="description"', false)
        ->assertSee('Вижте обявата в AutoSearch', false)
        ->assertSee('<meta property="og:type" content="product">', false)
        ->assertSee('<meta property="og:title" content="BMW X5 xDrive40i">', false)
        ->assertSee('<meta property="og:url" content="'.route('car-detail', $car).'">', false)
        ->assertSee('<meta property="og:image" content="https://img.example.com/x5.webp">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
});

it('404s for an inactive listing', function (): void {
    $car = CarListing::factory()->inactive()->create();

    get(route('car-detail', $car))->assertNotFound();
});

it('builds a slug-based URL but still resolves by the leading id', function (): void {
    $car = CarListing::factory()->create(['title' => 'BMW X5 xDrive40i']);

    expect(route('car-detail', $car))->toEndWith('/cars/'.$car->id.'-bmw-x5-xdrive40i');

    get('/cars/'.$car->id.'-bmw-x5-xdrive40i')->assertOk(); // canonical slug URL
    get('/cars/'.$car->id)->assertOk();                     // legacy id-only URL still works
    get('/cars/'.$car->id.'-stale-old-slug')->assertOk();   // slug is cosmetic, id wins
});
