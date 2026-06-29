<?php

use App\Models\CarListing;
use App\Models\CarMake;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

it('shows a make page listing only that make\'s active cars', function (): void {
    $bmw = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    $audi = CarMake::factory()->create(['name' => 'Audi', 'slug' => 'audi']);

    $mine = CarListing::factory()->create(['car_make_id' => $bmw->id, 'title' => 'BMW X5 Keep']);
    $other = CarListing::factory()->create(['car_make_id' => $audi->id, 'title' => 'Audi A4 Hide']);

    get(route('make-listings', 'bmw'))
        ->assertOk()
        ->assertSee('BMW X5 Keep')
        ->assertDontSee('Audi A4 Hide');
});

it('404s for an unknown make slug', function (): void {
    get(route('make-listings', 'does-not-exist'))->assertNotFound();
});

it('404s for a make with no active listings', function (): void {
    $bmw = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    CarListing::factory()->inactive()->create(['car_make_id' => $bmw->id]);

    get(route('make-listings', 'bmw'))->assertNotFound();
});
