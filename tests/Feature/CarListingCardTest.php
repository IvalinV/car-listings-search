<?php

use App\Models\CarListing;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

it('renders a listing card with title, price and detail link', function (): void {
    $car = CarListing::factory()->create([
        'title' => 'BMW X5 Test',
        'price' => 25000,
    ]);

    get(route('car-listings'))
        ->assertOk()
        ->assertSee('BMW X5 Test')
        ->assertSee(url('/cars/'.$car->id), false);
});
