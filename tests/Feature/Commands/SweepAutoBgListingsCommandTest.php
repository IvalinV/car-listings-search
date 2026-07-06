<?php

use App\Models\CarListing;
use App\Models\CarMake;
use App\Models\CarModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function autobgListing(string $seoId, ?string $checkedAt = null): CarListing
{
    return CarListing::factory()->create([
        'source_urls' => ["https://www.auto.bg/obiava/$seoId/some-car"],
        'checked_at' => $checkedAt,
    ]);
}

it('bumps checked_at for listings whose seo_id is live and leaves absent ones untouched', function (): void {
    CarMake::factory()->create(['slug' => 'audi']);

    Http::fake([
        'www.auto.bg/api/srcresults/*' => Http::response([
            'data' => ['lastpage' => 1, 'adverts' => [['seo_id' => '111', 'active' => 1]]],
        ]),
    ]);

    $live = autobgListing('111');
    $dead = autobgListing('999');

    $this->artisan('listings:sweep-autobg')->assertSuccessful();

    expect($live->fresh()->checked_at)->not->toBeNull()
        ->and($dead->fresh()->checked_at)->toBeNull();
});

it('descends into catalog models when a make feed is truncated at the page cap', function (): void {
    $make = CarMake::factory()->create(['slug' => 'bmw']);
    CarModel::factory()->create(['car_make_id' => $make->id, 'slug' => '320']);

    Http::fake(function (Request $request) {
        $url = urldecode($request->url());

        if (str_contains($url, '/bmw/320/page')) {
            return Http::response(['data' => ['lastpage' => 1, 'adverts' => [['seo_id' => '222', 'active' => 1]]]]);
        }

        if (str_contains($url, '/bmw/page')) {
            return Http::response(['data' => ['lastpage' => 100, 'adverts' => [['seo_id' => '111', 'active' => 1]]]]);
        }

        return Http::response(['data' => ['lastpage' => 1, 'adverts' => []]]);
    });

    $fromMakePage = autobgListing('111');   // present on the truncated make's first page
    $fromModel = autobgListing('222');       // only reachable via model descent

    $this->artisan('listings:sweep-autobg')->assertSuccessful();

    expect($fromMakePage->fresh()->checked_at)->not->toBeNull()
        ->and($fromModel->fresh()->checked_at)->not->toBeNull();
});

it('does not bump when the make feed request fails', function (): void {
    CarMake::factory()->create(['slug' => 'audi']);

    Http::fake(['www.auto.bg/api/srcresults/*' => Http::response('', 500)]);

    $listing = autobgListing('111');

    $this->artisan('listings:sweep-autobg')->assertSuccessful();

    expect($listing->fresh()->checked_at)->toBeNull();
});

it('ignores listings with no auto.bg source url', function (): void {
    CarMake::factory()->create(['slug' => 'audi']);

    Http::fake([
        'www.auto.bg/api/srcresults/*' => Http::response([
            'data' => ['lastpage' => 1, 'adverts' => [['seo_id' => '111', 'active' => 1]]],
        ]),
    ]);

    $other = CarListing::factory()->create([
        'source_urls' => ['https://www.mobile.bg/obiava-111-x'],
        'checked_at' => null,
    ]);

    $this->artisan('listings:sweep-autobg')->assertSuccessful();

    expect($other->fresh()->checked_at)->toBeNull();
});
