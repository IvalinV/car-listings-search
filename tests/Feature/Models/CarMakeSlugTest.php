<?php

use App\Models\CarMake;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forbids two makes sharing a slug', function (): void {
    CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);

    expect(fn () => CarMake::factory()->create(['name' => 'BMW Group', 'slug' => 'bmw']))
        ->toThrow(QueryException::class);
});
