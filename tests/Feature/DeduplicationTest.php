<?php

use App\Services\Deduplication;
use Illuminate\Support\Facades\Http;

$params = ['mileage' => 201734, 'year' => 2012, 'fuel_type' => 'Diesel'];

it('treats every platform placeholder as "no image" and never fetches it', function (string $placeholder) use ($params): void {
    Http::preventStrayRequests();

    $hash = Deduplication::make($placeholder, $params);

    // Falls back to the param hash (sha256 hex) instead of a 16-char perceptual hash.
    expect($hash)->toHaveLength(64)
        ->and($hash)->toBe(Deduplication::make(null, $params));
})->with([
    'cars.bg car.jpg' => ['https://assets.cars.bg/desktop/images/car.jpg'],
    'mobile.bg nophoto' => ['https://www.mobile.bg/images/picturess/nophoto_490x341.svg'],
    'car24 nophoto svg' => ['https://photos.car24.bg/assets/images/nophoto_490x341.svg'],
    'auto.bg nophoto' => ['https://photos.auto.bg/assets/images/nophoto_490x341.svg'],
    'car24 raw noPhotoBig' => ['https://photos.car24.bg/assets/images/noPhotoBig.png'],
]);

it('does not merge placeholder listings that differ in mileage, year or fuel', function (): void {
    Http::preventStrayRequests();

    $a = Deduplication::make('https://assets.cars.bg/desktop/images/car.jpg', ['mileage' => 100000, 'year' => 2015, 'fuel_type' => 'Diesel']);
    $b = Deduplication::make('https://assets.cars.bg/desktop/images/car.jpg', ['mileage' => 50000, 'year' => 2020, 'fuel_type' => 'Petrol']);

    expect($a)->not->toBe($b);
});

it('separates two photo-less cars that share mileage, year and fuel but differ on price', function (): void {
    Http::preventStrayRequests();

    $base = ['mileage' => 150000, 'year' => 2018, 'fuel_type' => 'Diesel'];

    $cheap = Deduplication::make(null, [...$base, 'price' => 9000]);
    $pricey = Deduplication::make(null, [...$base, 'price' => 18000]);

    // Before price was part of the hash these collided into one record.
    expect($cheap)->not->toBe($pricey)
        ->and(Deduplication::make(null, [...$base, 'price' => 9000]))->toBe($cheap);
});
