<?php

use App\Models\CarListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

it('sorts date-less listings by created_at instead of floating them to the top', function (): void {
    CarListing::factory()->create([
        'title' => 'Has published date',
        'is_active' => true,
        'published_at' => '2026-06-14 16:00:00',
        'created_at' => '2020-01-01 00:00:00',
    ]);

    CarListing::factory()->create([
        'title' => 'No published date recent',
        'is_active' => true,
        'published_at' => null,
        'created_at' => '2026-06-10 00:00:00',
    ]);

    CarListing::factory()->create([
        'title' => 'No published date old',
        'is_active' => true,
        'published_at' => null,
        'created_at' => '2020-01-01 00:00:00',
    ]);

    Livewire::test('pages.car-listings')
        ->assertSeeInOrder([
            'Has published date',
            'No published date recent',
            'No published date old',
        ]);
});

it('ignores an unknown sort column and falls back to the publication date sort', function (): void {
    CarListing::factory()->create([
        'title' => 'Newest',
        'is_active' => true,
        'published_at' => '2026-06-14 16:00:00',
    ]);

    CarListing::factory()->create([
        'title' => 'Oldest',
        'is_active' => true,
        'published_at' => '2020-01-01 00:00:00',
    ]);

    Livewire::test('pages.car-listings', ['sortBy' => 'id; drop table car_listings'])
        ->assertOk()
        ->assertSeeInOrder(['Newest', 'Oldest']);
});