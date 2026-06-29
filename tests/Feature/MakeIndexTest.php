<?php

use App\Models\CarListing;
use App\Models\CarMake;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

it('lists makes that have active listings and links to their pages', function (): void {
    $bmw = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    $empty = CarMake::factory()->create(['name' => 'Empty', 'slug' => 'empty']);
    CarListing::factory()->create(['car_make_id' => $bmw->id]);

    get(route('make-index'))
        ->assertOk()
        ->assertSee('BMW')
        ->assertSee(route('make-listings', 'bmw'), false)
        ->assertDontSee(route('make-listings', 'empty'), false);
});
