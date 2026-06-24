<?php

use App\Models\CarMake;
use App\Services\ListingMakeModelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function resolver(): ListingMakeModelResolver
{
    return app(ListingMakeModelResolver::class);
}

it('resolves a simple make and model', function (): void {
    CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);

    $result = resolver()->resolve('BMW X5');

    expect($result['make']->name)->toBe('BMW')
        ->and($result['model']->name)->toBe('X5');
});

it('matches a two-word make from the catalog', function (): void {
    CarMake::factory()->create(['name' => 'Alfa Romeo', 'slug' => 'alfa-romeo']);

    $result = resolver()->resolve('Alfa Romeo 159');

    expect($result['make']->name)->toBe('Alfa Romeo')
        ->and($result['model']->name)->toBe('159');
});

it('canonicalizes an alias make to its full name', function (): void {
    $result = resolver()->resolve('VW Passat 2.8');

    expect($result['make']->name)->toBe('Volkswagen')
        ->and($result['model']->name)->toBe('Passat');
});

it('leaves the model null when only a make is present', function (): void {
    CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);

    $result = resolver()->resolve('BMW');

    expect($result['make']->name)->toBe('BMW')
        ->and($result['model'])->toBeNull();
});

it('rejects an engine-spec token as a model', function (): void {
    CarMake::factory()->create(['name' => 'Fiat', 'slug' => 'fiat']);

    $result = resolver()->resolve('Fiat 2.0 JTD');

    expect($result['make']->name)->toBe('Fiat')
        ->and($result['model'])->toBeNull();
});

it('returns null make and model for an empty title', function (): void {
    $result = resolver()->resolve('   ');

    expect($result['make'])->toBeNull()
        ->and($result['model'])->toBeNull();
});

it('treats Range Rover as the Land Rover make with the variant as model', function (string $title, string $expectedModel): void {
    CarMake::factory()->create(['name' => 'Land Rover', 'slug' => 'land-rover']);

    $result = resolver()->resolve($title);

    expect($result['make']->name)->toBe('Land Rover')
        ->and($result['model']->name)->toBe($expectedModel);
})->with([
    'leading Range Rover' => ['Range Rover Evoque 2.2 TD4', 'Evoque'],
    'Range Rover Sport' => ['Range Rover Sport 3.6 272hp', 'Sport'],
    'full Land Rover Range Rover' => ['Land Rover Range Rover Evoque Автоматик 2.2', 'Evoque'],
    'Land Rover Range Rover Vogue' => ['Land Rover Range Rover Vogue 4.4 D', 'Vogue'],
    'plain Land Rover model' => ['Land Rover Discovery', 'Discovery'],
]);

it('leaves the model null for a bare Range Rover title', function (): void {
    CarMake::factory()->create(['name' => 'Land Rover', 'slug' => 'land-rover']);

    $result = resolver()->resolve('Range Rover 3.0TD V6');

    expect($result['make']->name)->toBe('Land Rover')
        ->and($result['model'])->toBeNull();
});

it('auto-creates an unknown make and model, idempotently', function (): void {
    $first = resolver()->resolve('Tesla Model3');
    $second = resolver()->resolve('Tesla Model3');

    expect($first['make']->name)->toBe('Tesla')
        ->and($first['model']->name)->toBe('Model3')
        ->and(CarMake::where('name', 'Tesla')->count())->toBe(1)
        ->and($first['model']->id)->toBe($second['model']->id);
});
