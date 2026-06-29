<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Guards the performance fix: without indexes on these foreign keys, the
 * make/model dropdown counts and filters seq-scan the whole listings table on
 * every render (a multi-second regression).
 */
it('indexes the make and model foreign keys on car_listings', function (): void {
    expect(Schema::hasIndex('car_listings', ['car_make_id']))->toBeTrue()
        ->and(Schema::hasIndex('car_listings', ['car_model_id']))->toBeTrue();
});
