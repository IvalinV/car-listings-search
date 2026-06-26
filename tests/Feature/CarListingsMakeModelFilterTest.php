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

it('filters by model when a make has duplicate models sharing one slug', function (): void {
    $audi = CarMake::factory()->create(['name' => 'Audi', 'slug' => 'audi']);
    $catalogA4 = $audi->models()->create(['name' => 'Audi A4', 'slug' => 'a4']);
    $derivedA4 = $audi->models()->create(['name' => 'A4', 'slug' => 'a4']);

    CarListing::factory()->create(['title' => 'Catalog A4 listing', 'is_active' => true, 'car_make_id' => $audi->id, 'car_model_id' => $catalogA4->id]);
    CarListing::factory()->create(['title' => 'Derived A4 listing', 'is_active' => true, 'car_make_id' => $audi->id, 'car_model_id' => $derivedA4->id]);

    Livewire::test('pages.car-listings')
        ->set('make', 'audi')
        ->set('model', 'a4')
        ->assertSee('Catalog A4 listing')
        ->assertSee('Derived A4 listing');
});

it('lists a single deduplicated model option per slug with summed counts', function (): void {
    $audi = CarMake::factory()->create(['name' => 'Audi', 'slug' => 'audi']);
    $catalogA4 = $audi->models()->create(['name' => 'Audi A4', 'slug' => 'a4']);
    $derivedA4 = $audi->models()->create(['name' => 'A4', 'slug' => 'a4']);

    CarListing::factory()->create(['title' => 'Audi A4 one', 'is_active' => true, 'car_make_id' => $audi->id, 'car_model_id' => $catalogA4->id]);
    CarListing::factory()->create(['title' => 'Audi A4 two', 'is_active' => true, 'car_make_id' => $audi->id, 'car_model_id' => $derivedA4->id]);

    $models = collect(Livewire::test('pages.car-listings')->set('make', 'audi')->get('models'))
        ->where('slug', 'a4');

    expect($models)->toHaveCount(1)
        ->and($models->first()['count'])->toBe(2);
});

it('matches the canonical make name in search even when the title uses an alias', function (): void {
    seedCatalogListings();

    Livewire::test('pages.car-listings')
        ->set('search', 'Volkswagen')
        ->assertSee('VW Passat');
});

it('includes active listing counts in the make and model options', function (): void {
    seedCatalogListings();

    $makes = collect(Livewire::test('pages.car-listings')->get('makes'));

    expect($makes->firstWhere('slug', 'bmw')['count'])->toBe(2)
        ->and($makes->firstWhere('slug', 'volkswagen')['count'])->toBe(1)
        ->and($makes->firstWhere('slug', 'unspecified')['count'])->toBe(1);

    $models = collect(Livewire::test('pages.car-listings')->set('make', 'bmw')->get('models'));

    expect($models->firstWhere('slug', 'x5')['count'])->toBe(1)
        ->and($models->firstWhere('slug', 'unspecified')['count'])->toBe(1);
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
