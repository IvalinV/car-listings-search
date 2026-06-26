<?php

use App\Models\CarListing;
use App\Models\CarMake;
use App\Models\CarModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

it('renders SEO head for a make page', function (): void {
    $bmw = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    CarListing::factory()->count(3)->create(['car_make_id' => $bmw->id]);

    $response = get(route('make-listings', 'bmw'))->assertOk();

    $response
        ->assertSee('<title>BMW автомобили - AutoSearch</title>', false)
        ->assertSee('<meta name="description"', false)
        ->assertSee('<link rel="canonical" href="'.route('make-listings', 'bmw').'">', false)
        ->assertSee('"@type":"BreadcrumbList"', false)
        ->assertSee('"@type":"CollectionPage"', false);

    expect(substr_count($response->getContent(), '<h1'))->toBe(1);
});

it('links to the make\'s top models', function (): void {
    $bmw = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    $x5 = CarModel::factory()->create(['car_make_id' => $bmw->id, 'name' => 'X5', 'slug' => 'x5']);
    CarListing::factory()->create(['car_make_id' => $bmw->id, 'car_model_id' => $x5->id]);

    get(route('make-listings', 'bmw'))
        ->assertOk()
        ->assertSee(route('car-listings', ['make' => 'bmw', 'model' => 'x5']), false);
});
