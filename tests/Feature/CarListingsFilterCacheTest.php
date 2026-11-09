<?php

use App\Models\CarListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('caches the listing filter options', function (): void {
    CarListing::factory()->create([
        'fuel_type' => 'Diesel',
        'transmission' => 'Automatic',
        'location' => 'София',
    ]);

    $component = Livewire::test('pages.car-listings');

    $component->get('fuelTypes');
    $component->get('transmissions');
    $component->get('locations');

    expect(Cache::has('car-listings.filter-options.fuel-types'))->toBeTrue()
        ->and(Cache::has('car-listings.filter-options.transmissions'))->toBeTrue()
        ->and(Cache::has('car-listings.filter-options.locations'))->toBeTrue()
        ->and(Cache::has('car-listings.filter-options.makes'))->toBeTrue();
});
