<?php

namespace App\Services\Scrapers;

use App\Misc\LogChannels;
use GuzzleHttp\Client;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class Car24Scraper
{
    public function scrape($page = 1)
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'bg-BG,bg;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer' => 'https://www.cars24.bg/',
        ])->get("https://api.car24.bg/mobile_api/srcresults/?request_uri=obiavi/p-$page");

        try {
            $response->throwUnlessStatus(200);
        } catch (RequestException $e) {
            Log::channel(LogChannels::SCRAPING_CAR24)->error("Failed to scrape car24.bg ads for page $page - {$e->getMessage()}");
        }

        return $response->json('data.adverts');
    }
}
