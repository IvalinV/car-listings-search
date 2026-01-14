<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class MobileBgScraper
{
    /**
     * @return array<int, array{title: string, price: string, link: string|null, description: string, image: string|null}>
     */
    public function scrape(int $page = 1): array
    {
        // 1. Fetch HTML with specific headers to look like a browser
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'bg-BG,bg;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer' => 'https://www.mobile.bg/',
        ])->get("https://www.mobile.bg/pcgi/mobile.cgi?act=3&sink=1&f1={$page}");

        if (! $response->successful()) {
            return [];
        }

        // 2. Handle Encoding
        // Mobile.bg often uses Windows-1251 (CP1251).
        // We convert it to UTF-8 so the DomCrawler and your DB can read it.
        $html = $response->body();
//        $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1251');

        $crawler = new Crawler($html);
        $results = [];

        // 3. Parse the listings
        // Mobile.bg uses table-based layouts or specific list item classes.
        // Current listings are typically within .item or table structures.
        $crawler->filter('.ads2023')->each(function (Crawler $node) use (&$results) {
            try {
                $results[] = [
                    'title' => trim($node->filter('.title')->text('')),
                    'price' => trim($node->filter('.price')->text('')),
                    'link' => $node->filter('.zaglavie>a')->count() > 0 ? trim($node->filter('.zaglavie>a')->attr('href')) : null,
                    'description' => trim($node->filter('.info')->text('')),
                    'image' => $node->filter('.photo .big a.image .pic')->count() > 0 ? $node->filter('.photo .big a.image .pic')->attr('src') : null,
                ];
            } catch (\Exception $e) {
                // Skip if parsing a specific node fails
            }
        });

        return $results;
    }
}
