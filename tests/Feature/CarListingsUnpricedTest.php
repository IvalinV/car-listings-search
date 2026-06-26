<?php

use App\Models\CarListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

it('hides listings with the placeholder price from the search results', function (): void {
    CarListing::factory()->create([
        'title' => 'Real priced car',
        'is_active' => true,
        'price' => 12000,
    ]);

    CarListing::factory()->create([
        'title' => 'Unpriced placeholder car',
        'is_active' => true,
        'price' => CarListing::UNPRICED_SENTINEL,
    ]);

    Livewire::test('pages.car-listings')
        ->assertSee('Real priced car')
        ->assertDontSee('Unpriced placeholder car');
});

it('the priced scope filters out sentinel, repeated-digit and sequential prices', function (): void {
    CarListing::factory()->count(2)->create(['price' => 5000]);
    CarListing::factory()->create(['price' => CarListing::UNPRICED_SENTINEL]);
    CarListing::factory()->create(['price' => 111111111]);
    CarListing::factory()->create(['price' => 1111111]);
    CarListing::factory()->create(['price' => 123456789]);
    CarListing::factory()->create(['price' => 123456]);

    expect(CarListing::query()->priced()->count())->toBe(2);
});

it('keeps plausible short repeated-digit prices like 5555', function (): void {
    CarListing::factory()->create(['price' => 5555]);
    CarListing::factory()->create(['price' => 999]);

    expect(CarListing::query()->priced()->count())->toBe(2);
});
