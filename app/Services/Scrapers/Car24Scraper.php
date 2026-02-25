<?php

namespace App\Services\Scrapers;

use App\Misc\LogChannels;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Car24Scraper extends Scraper
{
    private string $url_single_listing = "https://api.car24.bg/mobile_api/adverts/loadbyid";
    public function scrape($page = 1)
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'bg-BG,bg;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer' => 'https://www.car24.bg/',
        ])->get("https://api.car24.bg/mobile_api/srcresults/?request_uri=obiavi/p-$page");

        try {
            $response->throwUnlessStatus(200);
        } catch (RequestException $e) {
            Log::channel(LogChannels::SCRAPING_CAR24)->error("Failed to scrape car24.bg ads for page $page - {$e->getMessage()}");
        }

        $results = $response->json('data.adverts');

        return Arr::map($results, function ($item) {
            $images_array = Arr::get($item, 'bigPics', []);
            $image = count($images_array) ? Arr::first($item['bigPics'], fn ($value) => ! is_null($value)) : null;

            return [
                'title' => Arr::get($item, 'title'),
                'price' => Arr::get($item, 'price'),
                'currency' => Arr::get($item, 'currency'),
                'link' => 'https://car24.bg'.Arr::get($item, 'idalink'),
                'description' => $this->generateDescription($item),
                'image' => $this->getImage($image),
                'location' => Arr::get($item, 'locat'),
                'source' => 'car24.bg',
                'params' => [
                    'production_year' => Arr::get($item, 'year'),
                    'mileage' => Arr::get($item, 'km'),
                    'horsepower' => null,
                    'fuel' => $this->determineFuelType(Arr::get($item, 'engine_type')),
                    'engine_cc' => null,
                    'euro_standard' => null,
                    'last_updated_at' => null,
                    'transmission' => null,
                ],
            ];
        });
    }

    /**
     * Get single listing.
     *
     * @param $url
     * @return array|mixed|null
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public function getListing($url)
    {
        preg_match('/[\\\\\/]obiava[\\\\\/](\d+)(?=[\\\\\/]|$)/', $url, $matches);

        $id = $matches[1] ?? null;
        $title = Str::afterLast($url, '\\');;
        $title = ltrim($title, '\\');

        $response = Http::acceptJson()->withQueryParameters([
            'ida' => $id,
            'title' => $title,
        ])->get($this->url_single_listing);


        return $response->status() !== 404 ? $response->json('data.advert') : null;
    }

    /**
     * Generate listing description.
     *
     * @return string $description
     */
    private function generateDescription($item): string
    {
        $month = \Arr::get($item, 'month');
        $year = \Arr::get($item, 'year');
        $mileage = \Arr::get($item, 'km');
        $location = \Arr::get($item, 'locat');
        $modification = \Arr::get($item, 'modification');

        return "$month $year, $modification, $location, $mileage км";
    }

    public function getImage($url) : string
    {
        if (\Str::contains($url, 'noPhotoBig.png') || is_null($url)) {
            return 'https://photos.car24.bg/assets/images/nophoto_490x341.svg';
        }

        if (! \Str::isUrl($url)) {
            return "https:$url";
        }

        return $url;
    }
}
