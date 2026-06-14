<?php

namespace App\Services\Scrapers;

use App\Misc\LogChannels;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class MobileBgScraper extends Scraper
{
    /**
     * Earliest plausible creation timestamp (2010-01-01) used to reject
     * IDs that do not decode into a sane publication date.
     */
    private const MIN_CREATION_TIMESTAMP = 1262304000;

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
            'Referer' => 'https://www.mobile.bg/',
        ])->get("https://www.mobile.bg/pcgi/mobile.cgi?act=3&sink=1&f1=$page");

        if (! $response->successful()) {
            return [];
        }

        $html = $response->body();

        $crawler = new Crawler($html);
        $results = [];

        // 3. Parse the listings
        $crawler->filter('.ads2023 .item')->each(function (Crawler $node) use (&$results, $page) {
            try {
                $link = $node->filter('.zaglavie>a')->count() > 0
                    ? ltrim(trim($node->filter('.zaglavie>a')->attr('href')), '/')
                    : null;

                $results[] = [
                    'title' => trim($node->filter('.title')->text('')),
                    'price' => trim($node->filter('.price')->text('')),
                    'link' => $link,
                    'description' => \Str::excerpt(trim($node->filter('.info')->text('')), options: ['radius' => 500]),
                    'image' => $node->filter('.photo .big a.image .pic')->count() > 0 ? ltrim($node->filter('.photo .big a.image .pic')->attr('src'), '/') : null,
                    'location' => trim($node->filter('.location')->text()),
                    'source' => 'mobile.bg',
                    'published_at' => $this->getPublishedDate($link),
                    'params' => $this->extractListingParams($node->filter('.params')->first()->text()),
                ];
            } catch (\Exception $e) {
                // Skip if parsing a specific node fails
                Log::channel(LogChannels::SCRAPING_MOBILE)->error("Failed to scrape mobile.bg ads for page $page - {$e->getMessage()}");
            }
        });

        return $results;
    }

    /**
     * Derive the publication date from a listing link.
     *
     * mobile.bg listing IDs embed the creation Unix timestamp: dropping the
     * leading type-marker digit leaves the timestamp (in seconds) as the next
     * ten digits. This lets us read the publishing date straight from the results
     * page, without fetching each listing's detail page. The timestamp is an
     * absolute instant, so it is stored in the app timezone and formatted for
     * display elsewhere.
     */
    public function getPublishedDate(?string $link): ?Carbon
    {
        if (! $link || ! preg_match('/obiava-\d(\d{10})/', $link, $matches)) {
            return null;
        }

        $timestamp = (int) $matches[1];

        if ($timestamp < self::MIN_CREATION_TIMESTAMP || $timestamp > now()->addDay()->getTimestamp()) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp);
    }
}
