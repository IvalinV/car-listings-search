<?php

namespace App\Services\Scrapers;

use App\Misc\LogChannels;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

                $infoNode = $node->filter('.info');
                $infoText = $infoNode->count() > 0 ? trim($infoNode->text('')) : '';

                $results[] = [
                    'title' => $title,
                    'price' => $priceText ?: 'Contact for price',
                    'link' => $link,
                    'description' => $infoText,
                    'location' => $location,
                    'image' => $image,
                    'source' => 'auto.bg',
                    'params' => $this->extractListingParams($infoText),
                ];
            } catch (\Exception $e) {
                Log::channel(LogChannels::SCRAPING_AUTO)->error("Failed to scrape auto.bg ads for page $page - {$e->getMessage()}");
            }
        });

        return $results;
    }
}
