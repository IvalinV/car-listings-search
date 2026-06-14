<?php

namespace App\Services;

use App\Misc\LogChannels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Jenssegers\ImageHash\ImageHash;
use Jenssegers\ImageHash\Implementations\PerceptualHash;

class Deduplication
{
    /**
     * Placeholder "no photo" images each platform serves when a listing has no
     * picture. These must never be perceptually hashed, otherwise every
     * photo-less listing collapses into a single colliding record.
     *
     * - "noPhoto" matches mobile.bg / car24.bg / auto.bg (nophoto_490x341.svg)
     *   and car24.bg's raw "noPhotoBig.png".
     * - cars.bg uses a distinct "car.jpg" placeholder with no "nophoto" token.
     */
    private const PLACEHOLDER_IMAGE_PATTERNS = [
        'noPhoto',
        'assets.cars.bg/desktop/images/car.jpg',
    ];

    /**
     * Create unique hash to be used for removing duplicate entries.
     *
     * @param  array<string, mixed>|null  $params
     */
    public static function make(?string $imageUrl, ?array $params = null): string
    {
        if (blank($imageUrl) || Str::contains($imageUrl, self::PLACEHOLDER_IMAGE_PATTERNS, true)) {
            return self::paramsHash($params);
        }

        if (! Str::isUrl($imageUrl) && ! Str::contains($imageUrl, 'https')) {
            $imageUrl = ltrim($imageUrl, '/');
            $imageUrl = "https://$imageUrl";
        }

        try {
            $response = Http::retry(3)->connectTimeout(5)->timeout(10)->get($imageUrl);

            $hasher = new ImageHash(new PerceptualHash);
            $hash = $hasher->hash($response->body());

            return $hash->toHex();
        } catch (\Throwable $exception) {
            Log::channel(LogChannels::DEDUPLICATION)->error("Error getting $imageUrl for deduplication: {$exception->getMessage()}");

            return self::paramsHash($params);
        }
    }

    /**
     * Create unique hash with car listing parameters.
     *
     * Used only when there is no usable image. Price is included alongside
     * mileage/year/fuel because those three alone are too coarse and merge
     * unrelated cars; price sharply narrows the bucket.
     *
     * @param  array<string, mixed>|null  $params
     */
    private static function paramsHash(?array $params): string
    {
        $mileage = Arr::get($params, 'mileage');
        $year = Arr::get($params, 'year');
        $fuelType = Arr::get($params, 'fuel_type');
        $price = Arr::get($params, 'price');

        return hash('sha256', "$mileage $year $fuelType $price");
    }
}
