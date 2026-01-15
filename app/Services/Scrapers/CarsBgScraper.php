<?php

namespace App\Services\Scrapers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class CarsBgScraper
{
    /**
     * @return array<int, array{title: string, price: string, link: string|null, description: string, image: string|null}>
     *
     * @throws ConnectionException
     */
    public function scrape(int $page = 1): array
    {
        // 1. Fetch HTML with specific headers to look like a browser
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'bg-BG,bg;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer' => 'https://www.cars.bg/',
        ])->get("https://www.cars.bg/carslist.php?conditions%5B0%5D=4&conditions%5B1%5D=1&ajax=1&page=$page");

        if (! $response->successful()) {
            return [];
        }

        $html = $response->body();

        $crawler = new Crawler($html);
        $results = [];

        // 3. Parse the listings
        $crawler->filter('.mdc-layout-grid__cell.offer')->each(function (Crawler $node) use (&$results) {
            try {
                // Extract image from background-image style
                $image = null;
                $mediaNode = $node->filter('.mdc-card__media');
                if ($mediaNode->count() > 0) {
                    $style = $mediaNode->attr('style') ?? '';
                    if (preg_match('/background-image:\s*url\(["\']?([^"\')\s]+)["\']?\)/', $style, $matches)) {
                        $image = $matches[1];
                    }
                }

                $results[] = [
                    'title' => trim($node->filter('h5.card__title')->text('')),
                    'price' => trim($node->filter('.card__title.price')->text('')),
                    'link' => $node->filter('a[href]')->count() > 0 ? $node->filter('a[href]')->attr('href') : null,
                    'description' => trim($node->filter('.card__secondary.mdc-typography--body2')->text('')),
                    'image' => $image,
                    'location' => trim($node->filter('.card__footer')->text('')),
                    'source' => 'cars.bg',
                    'published_at' => $this->parseCreatedDate($node->filter('.card__subtitle')->text()),
                ];
            } catch (\Exception $e) {
                // Skip if parsing a specific node fails
                // TODO: Create separate log channel and log errors there
            }
        });

        return $results;
    }

    /**
     * Parse the date when the listing was published.
     *
     * @param  string  $input
     * @return string
     */
    private function parseCreatedDate(string $input) : string
    {
        // 1. Clean the string (remove trailing spaces and the comma)
        $cleanInput = trim(str_replace(',', '', $input));

        // 2. Check for the Bulgarian keyword "днес"
        if (str_contains($cleanInput, 'днес')) {
            // Extract the time part (14:25)
            $timePart = trim(str_replace('днес', '', $cleanInput));

            // Create Carbon instance starting at today and setting the time
            $date = today()->setTimeFromTimeString($timePart);
        }

        return $date->toDateTimeString();
    }
}
