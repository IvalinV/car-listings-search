<?php

use App\Models\CarListing;
use App\Models\CarMake;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links a listing to its make and model', function (): void {
    $make = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    $model = $make->models()->create(['name' => 'X5', 'slug' => 'x5']);

    $listing = CarListing::factory()->create([
        'car_make_id' => $make->id,
        'car_model_id' => $model->id,
    ]);

    expect($listing->make->name)->toBe('BMW')
        ->and($listing->model->name)->toBe('X5')
        ->and($make->listings)->toHaveCount(1)
        ->and($model->listings)->toHaveCount(1);
});

it('allows a listing with no make or model', function (): void {
    $listing = CarListing::factory()->create([
        'car_make_id' => null,
        'car_model_id' => null,
    ]);

    expect($listing->make)->toBeNull()
        ->and($listing->model)->toBeNull();
});
