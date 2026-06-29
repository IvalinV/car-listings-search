<?php

use App\Models\CarListing;
use App\Models\CarMake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Cache::flush();
});

it('lists make pages in the makes sitemap, excluding makes without active listings', function (): void {
    $bmw = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    $empty = CarMake::factory()->create(['name' => 'Empty', 'slug' => 'empty']);
    CarListing::factory()->create(['car_make_id' => $bmw->id]);

    get(route('sitemap.makes'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee('<urlset', false)
        ->assertSee(route('make-listings', 'bmw'), false)
        ->assertDontSee(route('make-listings', 'empty'), false);
});

it('references the makes sitemap from the index', function (): void {
    get('/sitemap.xml')
        ->assertOk()
        ->assertSee(route('sitemap.makes'), false);
});
