<?php

namespace App\Services\Scrapers;

use App\Misc\LogChannels;
use Carbon\Carbon;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class AutoBgScraper extends Scraper
{
    /**
     * Scrape a page of listings from auto.bg's JSON search API.
     *
     * The public site is an Angular SPA; this is the same endpoint it calls, so
     * every field arrives structured instead of parsed out of rendered HTML. The
     * description and price strings are reconstructed to the shape the shared
     * extractListingParams()/extractPrice()/getPublishedDate() helpers expect, so
     * downstream parsing and deduplication behave identically to the old scraper.
     *
     * @return array<int, array{title: string, price: string, link: string|null, description: string, location: string|null, image: string|null, published_at: Carbon|null, source: string, params: array<string, mixed>}>
     *
     * @throws ConnectionException
     */
    public function scrape(int $page = 1): array
    {
        $slug = "/avtomobili-dzhipove/page/$page";

        $response = Http::withHeaders([
            ...$this->browserHeaders(),
            'Accept' => 'application/json, text/plain, */*',
            'Referer' => 'https://www.auto.bg/obiavi/avtomobili-dzhipove/page/'.$page,
        ])->get("https://www.auto.bg/api/srcresults/$page", ['slug' => $slug]);

        if (! $response->successful()) {
            return [];
        }

        $results = [];

        foreach ((array) $response->json('data.adverts', []) as $advert) {
            try {
                $results[] = $this->mapAdvert($advert);
            } catch (\Exception $e) {
                Log::channel(LogChannels::SCRAPING_AUTO)->error("Failed to map auto.bg advert on page $page - {$e->getMessage()}");
            }
        }

        return $results;
    }

    /**
     * Map a single JSON advert to the shared scraped-listing shape.
     *
     * @param  array<string, mixed>  $advert
     * @return array{title: string, price: string, link: string|null, description: string, location: string|null, image: string|null, published_at: Carbon|null, source: string, params: array<string, mixed>}
     */
    private function mapAdvert(array $advert): array
    {
        $url = (string) Arr::get($advert, 'url', '');
        $link = $url !== '' ? 'https://www.auto.bg'.$url : null;

        $image = (string) Arr::get($advert, 'pict', '');
        if ($image !== '' && ! str_starts_with($image, 'http')) {
            $image = 'https:'.$image;
        }

        $location = trim((string) Arr::get($advert, 'locat', '')) ?: null;
        $year = trim((string) Arr::get($advert, 'year', ''));
        $month = trim((string) Arr::get($advert, 'month', ''));
        $km = trim((string) Arr::get($advert, 'km', ''));
        $fuel = trim((string) Arr::get($advert, 'engine_type', ''));

        $pills = array_filter([
            $year !== '' ? trim("$month $year").' г.' : '',
            $km !== '' ? "$km км." : '',
            $fuel,
        ]);

        $description = implode(' · ', array_filter([...$pills, $location]));

        $priceText = trim((string) Arr::get($advert, 'price', ''));
        $price2 = trim((string) Arr::get($advert, 'price2', ''));
        $price = trim($priceText.' '.$price2);

        return [
            'title' => trim((string) Arr::get($advert, 'title', '')) ?: 'N/A',
            'price' => $price !== '' ? $price : 'Contact for price',
            'link' => $link,
            'description' => $description,
            'location' => $location,
            'image' => $image ?: null,
            'published_at' => $this->getPublishedDate(Arr::get($advert, 'pubtime')),
            'source' => 'auto.bg',
            'params' => $this->extractListingParams($description),
        ];
    }

    /**
     * @return array<int, array{name: string, slug: string|null}>
     *
     * @throws ConnectionException
     */
    public function scrapeMakes(): array
    {
        $response = Http::withHeaders($this->browserHeaders())
            ->get('https://www.auto.bg/obiavi/avtomobili-dzhipove');

        if (! $response->successful()) {
            return [];
        }

        $crawler = new Crawler($response->body());
        $makes = [];

        $crawler->filter('a[href^="/obiavi/avtomobili-dzhipove/"]')->each(function (Crawler $node) use (&$makes): void {
            $href = (string) $node->attr('href');

            if (! preg_match('#^/obiavi/avtomobili-dzhipove/([a-z0-9-]+)$#', $href, $matches)) {
                return;
            }

            $name = trim($node->text(''));

            if ($name !== '') {
                $makes[$matches[1]] = ['name' => $name, 'slug' => $matches[1]];
            }
        });

        return array_values($makes);
    }

    /**
     * @return array<int, array{name: string, slug: string}>
     *
     * @throws ConnectionException
     */
    public function scrapeModels(string $makeSlug): array
    {
        $response = Http::withHeaders($this->browserHeaders())
            ->get("https://www.auto.bg/obiavi/avtomobili-dzhipove/{$makeSlug}");

        if (! $response->successful()) {
            return [];
        }

        $crawler = new Crawler($response->body());
        $models = [];
        $pattern = '#^/obiavi/avtomobili-dzhipove/'.preg_quote($makeSlug, '#').'/([a-z0-9-]+)$#';

        $crawler->filter('a[href^="/obiavi/avtomobili-dzhipove/'.$makeSlug.'/"]')->each(function (Crawler $node) use (&$models, $pattern): void {
            $href = (string) $node->attr('href');

            if (! preg_match($pattern, $href, $matches)) {
                return;
            }

            $name = trim($node->text(''));

            if ($name !== '') {
                $models[$matches[1]] = ['name' => $name, 'slug' => $matches[1]];
            }
        });

        return array_values($models);
    }

    /**
     * A live auto.bg listing returns 200 on its /obiava/ URL. A removed one is
     * redirected (301) to the brand/model category page (/obiavi/...), and a
     * never-existed ID returns a genuine 404.
     */
    public function isListingRemoved(string $url): bool
    {
        $response = Http::withoutRedirecting()
            ->withHeaders($this->browserHeaders())
            ->get($url);

        if ($response->notFound()) {
            return true;
        }

        return $response->redirect()
            && str_contains((string) $response->header('Location'), '/obiavi/');
    }

    public function poolRemovalProbe(PendingRequest $request, string $url): PromiseInterface
    {
        return $request->withoutRedirecting()->withHeaders($this->browserHeaders())->get($url);
    }

    public function isRemovedFromResponse(Response $response, string $url): bool
    {
        if ($response->notFound()) {
            return true;
        }

        return $response->redirect()
            && str_contains((string) $response->header('Location'), '/obiavi/');
    }

    /**
     * Parse the auto.bg listing date as written.
     *
     * Handles both "11:37 часа от днес" (relative to today) and
     * "23:50 часа от 13.06.2026" (absolute). The value is stored as-is;
     * timezone-aware formatting is applied on the front end.
     */
    public function getPublishedDate(?string $timeString): ?Carbon
    {
        if (! $timeString) {
            return null;
        }

        $exploded = explode(' ', $timeString);
        $time = Arr::get($exploded, 0);
        $date = Arr::get($exploded, 3);

        try {
            return Str::contains((string) $date, 'днес')
                ? Carbon::createFromFormat('H:i', $time)
                : Carbon::createFromFormat('d.m.Y H:i', "$date $time");
        } catch (\Throwable) {
            return null;
        }
    }
}
