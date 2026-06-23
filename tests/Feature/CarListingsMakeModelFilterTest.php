<?php

use App\Models\CarListing;
use App\Models\CarMake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

function seedCatalogListings(): array
{
    $bmw = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    $x5 = $bmw->models()->create(['name' => 'X5', 'slug' => 'x5']);
    $vw = CarMake::factory()->create(['name' => 'Volkswagen', 'slug' => 'volkswagen']);

    CarListing::factory()->create(['title' => 'BMW X5', 'is_active' => true, 'car_make_id' => $bmw->id, 'car_model_id' => $x5->id]);
    CarListing::factory()->create(['title' => 'BMW unspecified model', 'is_active' => true, 'car_make_id' => $bmw->id, 'car_model_id' => null]);
    CarListing::factory()->create(['title' => 'VW Passat', 'is_active' => true, 'car_make_id' => $vw->id, 'car_model_id' => null]);
    CarListing::factory()->create(['title' => 'Mystery car', 'is_active' => true, 'car_make_id' => null, 'car_model_id' => null]);

    return compact('bmw', 'x5', 'vw');
}

it('filters listings by make', function (): void {
    seedCatalogListings();

    Livewire::test('pages.car-listings')
        ->set('make', 'bmw')
        ->assertSee('BMW X5')
        ->assertSee('BMW unspecified model')
        ->assertDontSee('VW Passat');
});

it('surfaces null-make listings under the Unspecified make option', function (): void {
    seedCatalogListings();

    Livewire::test('pages.car-listings')
        ->set('make', 'unspecified')
        ->assertSee('Mystery car')
        ->assertDontSee('BMW X5');
});

it('filters by model and by Unspecified model within a make', function (): void {
    seedCatalogListings();

    Livewire::test('pages.car-listings')
        ->set('make', 'bmw')
        ->set('model', 'x5')
        ->assertSee('BMW X5')
        ->assertDontSee('BMW unspecified model');

    Livewire::test('pages.car-listings')
        ->set('make', 'bmw')
        ->set('model', 'unspecified')
        ->assertSee('BMW unspecified model')
        ->assertDontSee('BMW X5');
});

it('matches the canonical make name in search even when the title uses an alias', function (): void {
    seedCatalogListings();

    Livewire::test('pages.car-listings')
        ->set('search', 'Volkswagen')
        ->assertSee('VW Passat');
});

it('keeps every active listing reachable through some make filter value', function (): void {
    seedCatalogListings();

    $component = Livewire::test('pages.car-listings');
    $makeSlugs = collect($component->get('makes'))->pluck('slug');

    $reachable = collect();
    foreach ($makeSlugs as $slug) {
        $ids = Livewire::test('pages.car-listings')->set('make', $slug)->get('listings')->pluck('id');
        $reachable = $reachable->merge($ids);
    }

    expect($reachable->unique()->sort()->values()->all())
        ->toBe(CarListing::where('is_active', true)->orderBy('id')->pluck('id')->all());
});
