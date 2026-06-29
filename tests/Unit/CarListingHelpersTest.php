<?php

use App\Models\CarListing;

it('normalizes the display image url', function (): void {
    expect((new CarListing(['image_url' => 'cdn.x/a.webp']))->displayImageUrl())
        ->toBe('https://cdn.x/a.webp');
    expect((new CarListing(['image_url' => 'https://cdn.x/a.webp']))->displayImageUrl())
        ->toBe('https://cdn.x/a.webp');
    expect((new CarListing(['image_url' => null]))->displayImageUrl())->toBeNull();
});

it('labels a source url by host', function (): void {
    $car = new CarListing;
    expect($car->sourceLabel('https://www.cars.bg/offer/1'))->toBe('cars.bg');
    expect($car->sourceLabel('https://www.mobile.bg/obiava/1'))->toBe('mobile.bg');
    expect($car->sourceLabel('https://www.car24.bg/x'))->toBe('car24.bg');
    expect($car->sourceLabel('https://www.auto.bg/x'))->toBe('auto.bg');
    expect($car->sourceLabel('https://example.com/x'))->toBeNull();
});
