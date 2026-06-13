<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

it('loads the listings homepage successfully', function (): void {
    $this->withoutVite();

    get('/')
        ->assertOk()
        ->assertSee('Обяви за автомобили', false);
});
