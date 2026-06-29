<?php

use App\Models\CarListing;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

it('shows original publication, last update and every per-source date on a card', function (): void {
    CarListing::factory()->create([
        'title' => 'Audi A4 AVANT',
        'is_active' => true,
        'source_dates' => [
            'mobile.bg' => '2019-10-30 09:00:12',
            'cars.bg' => '2026-06-11 09:23:00',
        ],
        'published_at' => '2026-06-11 09:23:00',
    ]);

    get(route('car-listings'))
        ->assertOk()
        ->assertSeeText('Създадена:')
        ->assertSeeText('30.10.2019')   // earliest source date (original publication)
        ->assertSeeText('Обновена:')
        ->assertSeeText('11.06.2026')   // latest source date (= published_at)
        ->assertSeeText('mobile.bg')
        ->assertSeeText('cars.bg');
});

it('shows created and updated from a single cars.bg nested source entry', function (): void {
    CarListing::factory()->create([
        'title' => 'BMW 530',
        'is_active' => true,
        'source_dates' => [
            'cars.bg' => ['created' => '2025-11-05 10:39:59', 'updated' => '2026-06-16 07:40:00'],
        ],
        'published_at' => '2026-06-16 07:40:00',
    ]);

    get(route('car-listings'))
        ->assertOk()
        ->assertSeeText('Създадена:')
        ->assertSeeText('05.11.2025')   // created = decoded ObjectID
        ->assertSeeText('Обновена:')
        ->assertSeeText('16.06.2026')   // updated = bump date
        ->assertSeeText('cars.bg');
});

it('omits the "Обновена" part when a car has a single source date', function (): void {
    CarListing::factory()->create([
        'is_active' => true,
        'source_dates' => ['car24.bg' => ['created' => '2026-06-11 09:23:00']],
        'published_at' => '2026-06-11 09:23:00',
    ]);

    $response = get(route('car-listings'))->assertOk()->assertSeeText('Създадена:');

    expect($response->getContent())->not->toContain('Обновена:');
});

it('falls back to plain source badges for records without per-source dates', function (): void {
    CarListing::factory()->create([
        'is_active' => true,
        'source_dates' => null,
        'source_urls' => ['https://www.cars.bg/offer/abc123'],
        'published_at' => null,
    ]);

    get(route('car-listings'))
        ->assertOk()
        ->assertSeeText('cars.bg')
        ->assertDontSeeText('Създадена:');
});
