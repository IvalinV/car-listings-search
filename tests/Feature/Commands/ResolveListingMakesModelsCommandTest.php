<?php

use App\Models\CarListing;
use App\Models\CarMake;
use App\Models\CarModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves make and model for existing listings', function (): void {
    CarListing::factory()->create(['title' => 'BMW X5 3.0d', 'car_make_id' => null, 'car_model_id' => null]);
    CarListing::factory()->create(['title' => 'VW Passat 2.8', 'car_make_id' => null, 'car_model_id' => null]);

    $this->artisan('listings:resolve-makes-models')->assertSuccessful();

    $bmw = CarListing::where('title', 'BMW X5 3.0d')->first();
    expect($bmw->make->name)->toBe('BMW')
        ->and($bmw->model->name)->toBe('X5');

    $vw = CarListing::where('title', 'VW Passat 2.8')->first();
    expect($vw->make->name)->toBe('Volkswagen')
        ->and($vw->model->name)->toBe('Passat');
});

it('is idempotent and creates no duplicate catalog rows', function (): void {
    CarListing::factory()->create(['title' => 'BMW X5', 'car_make_id' => null, 'car_model_id' => null]);

    $this->artisan('listings:resolve-makes-models')->assertSuccessful();
    $this->artisan('listings:resolve-makes-models')->assertSuccessful();

    expect(CarMake::where('name', 'BMW')->count())->toBe(1)
        ->and(CarModel::where('name', 'X5')->count())->toBe(1);
});
