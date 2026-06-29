<?php

use App\Models\CarMake;
use App\Models\CarModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeMakeSources(): void
{
    Http::fake([
        'api.car24.bg/*' => Http::response([
            'status' => 'success',
            'data' => [
                'marki' => [['VW', 'vw']],
                'markiOther' => [['brand' => 'BMW', 'count' => '1', 'sef' => 'bmw']],
            ],
        ]),
        'www.cars.bg/*' => Http::response(
            '<div id="brandsList">'
            .'<span class="mdc-chip__text"><input name="brandId" value="0"/><label>Всички</label></span>'
            .'<span class="mdc-chip__text"><input name="brandId" value="1"/><label>BMW</label></span>'
            .'</div>'
        ),
        'www.mobile.bg/*' => Http::response(
            '<div id="akSearchMarki"><div class="scroll">'
            .'<div class="a"><span>Volkswagen</span> <span>1</span></div>'
            .'</div></div>'
        ),
        'www.auto.bg/obiavi/avtomobili-dzhipove/bmw' => Http::response(
            '<a href="/obiavi/avtomobili-dzhipove/bmw/320">320</a>'
            .'<a href="/obiavi/avtomobili-dzhipove/bmw/x5">X5</a>'
        ),
        'www.auto.bg/obiavi/avtomobili-dzhipove/volkswagen' => Http::response(
            '<a href="/obiavi/avtomobili-dzhipove/volkswagen/golf">Golf</a>'
        ),
        'www.auto.bg/obiavi/avtomobili-dzhipove' => Http::response(
            '<a href="/obiavi/avtomobili-dzhipove/bmw">BMW</a>'
            .'<a href="/obiavi/avtomobili-dzhipove/volkswagen">Volkswagen</a>'
        ),
    ]);
}

it('scrapes, dedupes and stores makes and models', function (): void {
    fakeMakeSources();

    $this->artisan('scrape:makes-models')->assertSuccessful();

    expect(CarMake::pluck('name')->all())->toEqualCanonicalizing(['Volkswagen', 'BMW'])
        ->and(CarMake::where('name', 'VW')->exists())->toBeFalse();

    $bmw = CarMake::where('name', 'BMW')->first();
    expect($bmw->models->pluck('name')->all())->toEqualCanonicalizing(['320', 'X5']);

    $vw = CarMake::where('name', 'Volkswagen')->first();
    expect($vw->models->pluck('name')->all())->toBe(['Golf']);
});

it('is idempotent across repeated runs', function (): void {
    fakeMakeSources();

    $this->artisan('scrape:makes-models')->assertSuccessful();
    $this->artisan('scrape:makes-models')->assertSuccessful();

    expect(CarMake::count())->toBe(2)
        ->and(CarModel::count())->toBe(3);
});
