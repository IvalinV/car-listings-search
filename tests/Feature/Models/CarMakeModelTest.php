<?php

use App\Models\CarMake;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('relates a make to its models', function (): void {
    $make = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    $make->models()->create(['name' => '320', 'slug' => '320']);

    expect($make->models)->toHaveCount(1)
        ->and($make->models->first()->make->name)->toBe('BMW');
});

it('enforces a unique make name', function (): void {
    CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);

    expect(fn (): CarMake => CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']))
        ->toThrow(QueryException::class);
});

it('enforces a unique model name within a make', function (): void {
    $make = CarMake::factory()->create();
    $make->models()->create(['name' => '320', 'slug' => '320']);

    expect(fn () => $make->models()->create(['name' => '320', 'slug' => '320']))
        ->toThrow(QueryException::class);
});
