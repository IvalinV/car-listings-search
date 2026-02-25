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
     * Create unique hash to be used for removing duplicate entries.
     *
     * @param  array<string, mixed>|null  $params
     */
    public static function make(?string $imageUrl, ?array $params = null): string
    {
        if (blank($imageUrl) || Str::contains($imageUrl, 'noPhoto', true)) {
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
     * @param  array<string, mixed>|null  $params
     */
    private static function paramsHash(?array $params): string
    {
        $mileage = Arr::get($params, 'mileage');
        $year = Arr::get($params, 'year');
        $fuelType = Arr::get($params, 'fuel_type');

        return hash('sha256', "$mileage $year $fuelType");
    }
}
