<?php

namespace App\Services\Scrapers;

use App\Misc\LogChannels;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class AutoBgScraper
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

        $crawler->filter('.resultItem')->each(function (Crawler $node) use (&$results, $page) {
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
                $linkNode = $node->filter('.head .link a');
                if ($linkNode->count() > 0) {
                    $link = $linkNode->attr('href');
                }

                $priceText = '';
                $priceNode = $node->filter('.head .price');
                if ($priceNode->count() > 0) {
                    $priceText = trim(preg_replace('/\s+/', ' ', $priceNode->text('')));
                    // Remove the VAT note if present
                    $priceText = preg_replace('/Не се начислява ДДС/', '', $priceText);
                    $priceText = trim($priceText);
                }

                $results[] = [
                    'title' => $linkNode->count() > 0 ? trim($linkNode->text('')) : 'N/A',
                    'price' => $priceText ?: 'Contact for price',
                    'link' => $link,
                    'description' => trim($node->filter('.info')->text('')),
                    'image' => $image,
                    'source' => 'auto.bg',
                    'params' => $this->extractListingParams(trim($node->filter('.info')->text(''))),
                ];
            } catch (\Exception $e) {
                Log::channel(LogChannels::SCRAPING_AUTO)->error("Failed to scrape auto.bg ads for page $page - {$e->getMessage()}");
            }
        });

        return $results;
    }

    public function extractListingParams($input): array
    {
        /**
         * 1. Split the string into segments using the pipe "|" delimiter.
         */
        $parts = array_map('trim', explode('|', $input));

        // Correctly identifying the first segment as Production Date
        $productionRaw = $parts[0] ?? null; // e.g., "януари 2018 г."
        $mileage       = $parts[1] ?? null; // e.g., "194844 км"
        $transmission  = $parts[2] ?? null; // e.g., "Автоматична"
        $engineType    = $parts[3] ?? null; // e.g., "Бензин"

        /**
         * 2. Parse Production Year and Month
         * We look for a 4-digit year.
         */
        $productionYear = null;
        if (preg_match('/\d{4}/', $productionRaw, $yearMatch)) {
            $productionYear = $yearMatch[0]; // Result: 2018
        }

        /**
         * 3. Processing the last segment (Index 4).
         */
        $lastPart = $parts[4] ?? '';

        // Extract Horsepower
        if (preg_match('/^(\d+)\s*к\.с\./u', $lastPart, $hpMatch)) {
            $horsepower = $hpMatch[1]; // Result: 270
        }

        // Extract Listing Update Time
        if (preg_match('/(\d{2}:\d{2})/', $lastPart, $timeMatch)) {
            $time = $timeMatch[1]; // Result: 15:38
        }

        /**
         * Creating a Carbon instance for the Listing's actual "Update" time.
         */
        if (isset($time)) {
            $listingUpdated = \Carbon\Carbon::now('Europe/Sofia')->setTimeFromTimeString($time);
        }

        /**
         * Outputting the parsed data
         */
        return [
            'production_year' => $productionYear ?? null,
            'mileage' => $mileage,
            'horsepower"' => $horsepower ?? null,
            'fuel' => $engineType,
            'last_updated_at' => $listingUpdated?->toDateTimeString() ?? null,
            'transmission' => $transmission ?? null,
        ];
    }
}
