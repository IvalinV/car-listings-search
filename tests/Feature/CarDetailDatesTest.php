<?php

use App\Models\CarListing;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

it('shows original publication, last update and per-source dates on the detail page', function (): void {
    $car = CarListing::factory()->create([
        'source_urls' => [
            'https://mobile.bg/obiava-11572426012950088-audi',
            'https://www.cars.bg/offer/abc123',
        ],
        'source_dates' => [
            'mobile.bg' => '2019-10-30 09:00:12',
            'cars.bg' => '2026-06-11 09:23:00',
        ],
        'published_at' => '2026-06-11 09:23:00',
    ]);

    get(route('car-detail', $car))
        ->assertOk()
        ->assertSeeText('Създадена:')
        ->assertSeeText('30.10.2019')   // earliest source date
        ->assertSeeText('Обновена:')
        ->assertSeeText('11.06.2026')   // latest source date (= published_at)
        ->assertSeeText('mobile.bg')
        ->assertSeeText('cars.bg');
});

it('falls back to a single published date when there are no per-source dates', function (): void {
    $car = CarListing::factory()->create([
        'source_dates' => null,
        'published_at' => '2026-06-11 09:23:00',
    ]);

    $response = get(route('car-detail', $car))->assertOk()->assertSeeText('Публикувана:');

    expect($response->getContent())->not->toContain('Създадена:');
});
