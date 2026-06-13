<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

it('renders a single descriptive h1 on the listings page', function (): void {
    $response = get(route('car-listings'))->assertOk();

    $response->assertSee('<h1', false)
        ->assertSeeText('Обяви за автомобили');

    // Exactly one <h1> on the page.
    expect(substr_count($response->getContent(), '<h1'))->toBe(1);
});

it('includes a meta description and Open Graph tags on the listings page', function (): void {
    get(route('car-listings'))
        ->assertOk()
        ->assertSee('<meta name="description"', false)
        ->assertSee('<meta property="og:type" content="website">', false)
        ->assertSee('<meta property="og:title"', false)
        ->assertSee('<meta property="og:url" content="'.route('car-listings').'">', false)
        ->assertSee('<meta property="og:image" content="'.asset('images/marketing-image.png').'">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
});
