<?php

namespace App\Services\Scrapers;

use App\Misc\LogChannels;
use Carbon\Carbon;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Car24Scraper extends Scraper
{
    private string $url_single_listing = 'https://api.car24.bg/mobile_api/adverts/loadbyid';

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
            $published_at = $this->getPublishedDate(Arr::get($item, 'pubtime'));

            return [
                'title' => Arr::get($item, 'title'),
                'price' => Arr::get($item, 'price'),
                'currency' => Arr::get($item, 'currency'),
                'link' => 'https://car24.bg'.Arr::get($item, 'idalink'),
                'description' => $this->generateDescription($item),
                'image' => $this->getImage($image),
                'location' => Arr::get($item, 'locat'),
                'source' => 'car24.bg',
                'published_at' => $published_at,
                'params' => [
                    'production_year' => Arr::get($item, 'year'),
                    'mileage' => Arr::get($item, 'km'),
                    'horsepower' => null,
                    'fuel' => $this->determineFuelType(Arr::get($item, 'engine_type')),
                    'engine_cc' => null,
                    'euro_standard' => null,
                    'transmission' => null,
                ],
            ];
        });
    }

    /**
     * @return array<int, array{name: string, slug: string|null}>
     *
     * @throws ConnectionException
     */
    public function scrapeMakes(): array
    {
        $response = Http::withHeaders($this->browserHeaders())
            ->get('https://api.car24.bg/mobile_api/brands');

        if (! $response->successful()) {
            return [];
        }

        $popular = Arr::map(
            $response->json('data.marki', []),
            fn (array $pair): array => ['name' => $pair[0], 'slug' => $pair[1] ?? null],
        );

        $other = Arr::map(
            $response->json('data.markiOther', []),
            fn (array $brand): array => ['name' => $brand['brand'], 'slug' => $brand['sef'] ?? null],
        );

        return [...$popular, ...$other];
    }

    /**
     * car24.bg soft-deletes: the public /obiava/ URL 301s a removed listing to
     * a category page (HTTP 200), so the HTML is unreliable. The mobile API
     * returns the advert only while it is live (data.advert is null once the
     * listing is removed or never existed), making it the authoritative check.
     *
     * A transient API failure (5xx, rate limit) bubbles up as an exception
     * rather than being misread as a removed listing.
     *
     * @throws ConnectionException|RequestException
     */
    public function isListingRemoved(string $url): bool
    {
        return is_null($this->getListing($url));
    }

    /**
     * Extract the (ida, title) pair the mobile API needs from an /obiava/ URL.
     *
     * @return array{0: ?string, 1: string}
     */
    private function advertQuery(string $url): array
    {
        preg_match('/[\\\\\/]obiava[\\\\\/](\d+)(?=[\\\\\/]|$)/', $url, $matches);

        return [$matches[1] ?? null, Str::afterLast($url, '/')];
    }

    public function poolRemovalProbe(PendingRequest $request, string $url): PromiseInterface
    {
        [$id, $title] = $this->advertQuery($url);

        return $request->acceptJson()
            ->withQueryParameters(['ida' => $id, 'title' => $title])
            ->get($this->url_single_listing);
    }

    public function isRemovedFromResponse(Response $response, string $url): bool
    {
        if ($response->status() === 404) {
            return true;
        }

        if (! $response->successful()) {
            return false;
        }

        return is_null($response->json('data.advert'));
    }

    /**
     * Get single listing.
     *
     * Returns the advert payload while the listing is live, null once it has
     * been removed (HTTP 404, or HTTP 200 with no advert). Any other error
     * status is thrown so callers do not mistake a transient failure for a
     * removed listing.
     *
     * @return array<string, mixed>|null
     *
     * @throws ConnectionException|RequestException
     */
    public function getListing($url): ?array
    {
        preg_match('/[\\\\\/]obiava[\\\\\/](\d+)(?=[\\\\\/]|$)/', $url, $matches);

        $id = $matches[1] ?? null;
        $title = Str::afterLast($url, '/');

        $response = Http::acceptJson()->withQueryParameters([
            'ida' => $id,
            'title' => $title,
        ])->get($this->url_single_listing);

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return $response->json('data.advert');
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

    public function getImage($url): string
    {
        if (\Str::contains($url, 'noPhotoBig.png') || is_null($url)) {
            return 'https://photos.car24.bg/assets/images/nophoto_490x341.svg';
        }

        if (! \Str::isUrl($url)) {
            return "https:$url";
        }

        return $url;
    }

    /**
     * Parse the car24.bg "pubtime" (e.g. "12:30 на 12.06.2026") as written.
     *
     * The value is stored as-is; timezone-aware formatting happens on the front end.
     */
    public function getPublishedDate(?string $dateString): ?Carbon
    {
        if (! $dateString) {
            return null;
        }

        try {
            return Carbon::createFromFormat('H:i d.m.Y', str_replace('на ', '', $dateString));
        } catch (\Throwable) {
            return null;
        }
    }
}
