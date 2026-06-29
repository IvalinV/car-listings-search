<?php

namespace App\Services\Scrapers;

use App\Misc\LogChannels;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class AutoBgScraper extends Scraper
{
    /**
     * @return array<int, array{title: string, price: string, link: string|null, description: string, image: string|null}>
     *
     * @throws ConnectionException
     */
    public function scrape(int $page = 1): array
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'bg-BG,bg;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer' => 'https://www.auto.bg/',
        ])->get("https://www.auto.bg/obiavi/avtomobili-dzhipove/page/$page?nup=013&searchres=g14z70i1&sort=1");

        if (! $response->successful()) {
            return [];
        }

        $html = $response->body();
        $crawler = new Crawler($html);
        $results = [];

        $crawler->filter('my-advert-l')->each(function (Crawler $node) use (&$results, $page) {
            try {
                $image = null;
                $imgNode = $node->filter('.photo img');
                if ($imgNode->count() > 0) {
                    $image = $imgNode->attr('src');
                    if ($image && ! str_starts_with($image, 'http')) {
                        $image = 'https:'.$image;
                    }
                }

                $link = null;
                $linkNode = $node->filter('a[href]');
                if ($linkNode->count() > 0) {
                    $link = $linkNode->attr('href');
                    if ($link && str_starts_with($link, '/')) {
                        $link = 'https://www.auto.bg'.$link;
                    }
                }

                $titleNode = $node->filter('.title');
                $title = $titleNode->count() > 0 ? trim($titleNode->text('')) : 'N/A';

                $priceText = '';
                $priceNode = $node->filter('.price');
                if ($priceNode->count() > 0) {
                    $priceText = trim(preg_replace('/\s+/', ' ', $priceNode->text('')));
                    // Remove the VAT note if present
                    $priceText = preg_replace('/Цената е с включено ДДС|Не се начислява ДДС/u', '', $priceText);
                    $priceText = trim($priceText);
                }

                $locationNode = $node->filter('.location');
                $location = $locationNode->count() > 0 ? trim(preg_replace('/\s+/', ' ', $locationNode->text(''))) : null;

                $dateNode = $node->filter('.date');
                $date = $dateNode->count() > 0 ? trim(preg_replace('/\s+/', ' ', $dateNode->text(''))) : null;

                $pills = $node->filter('.pills .pill')->each(
                    fn (Crawler $pill): string => trim(preg_replace('/\s+/', ' ', $pill->text('')))
                );

                $description = implode(' · ', array_filter([...$pills, $location]));

                $results[] = [
                    'title' => $title,
                    'price' => $priceText ?: 'Contact for price',
                    'link' => $link,
                    'description' => $description,
                    'location' => $location,
                    'image' => $image,
                    'published_at' => $this->getPublishedDate($date),
                    'source' => 'auto.bg',
                    'params' => $this->extractListingParams($description),
                ];
            } catch (\Exception $e) {
                Log::channel(LogChannels::SCRAPING_AUTO)->error("Failed to scrape auto.bg ads for page $page - {$e->getMessage()}");
            }
        });

        return $results;
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
