<?php

namespace App\Services\Scrapers;

use App\Misc\LogChannels;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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
        $response = Http::withHeaders([
            ...$this->browserHeaders(),
            'Referer' => 'https://www.mobile.bg/',
        ])->get("https://www.mobile.bg/pcgi/mobile.cgi?act=3&sink=1&f1=$page");

        if (! $response->successful()) {
            return [];
        }

        return $this->parseCards(new Crawler($response->body()), "page $page");
    }

    /**
     * Scrape one page of a make (or make/model) segment via the slug URL.
     * Page 1 has no suffix; later pages use the `/p-N` form. Reuses the shared
     * `.ads2023 .item` card parser. Pages are served as windows-1251.
     *
     * A non-2xx response throws (rather than returning `[]`) so the page-walker
     * job retries the same page instead of mistaking a server hiccup for the end
     * of results. A 200 page that parses to no cards is the genuine end and
     * returns `[]`.
     *
     * @return array<int, array{title: string, price: string, link: string|null, description: string, image: string|null, location: string, source: string, published_at: Carbon|null, params: array<string, mixed>}>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function scrapeSegment(string $slug, int $page = 1): array
    {
        $suffix = $page > 1 ? "/p-$page" : '';

        $response = Http::withHeaders([
            ...$this->browserHeaders(),
            'Referer' => 'https://www.mobile.bg/',
        ])
            ->connectTimeout((int) config('listings.mobilebg_sweep.connect_timeout'))
            ->timeout((int) config('listings.mobilebg_sweep.request_timeout'))
            ->get("https://www.mobile.bg/obiavi/avtomobili-dzhipove/{$slug}{$suffix}");

        $response->throw();

        $crawler = new Crawler;
        $crawler->addHtmlContent($response->body(), 'windows-1251');

        return $this->parseCards($crawler, "segment $slug p$page");
    }

    /**
     * Parse `.ads2023 .item` result cards into the shared scraped-listing shape.
     * `$context` labels failures in the log (page number or segment slug).
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseCards(Crawler $crawler, string $context): array
    {
        $results = [];

        $crawler->filter('.ads2023 .item')->each(function (Crawler $node) use (&$results, $context): void {
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
                    'location' => trim($node->filter('.location')->text('')),
                    'source' => 'mobile.bg',
                    'published_at' => $this->getPublishedDate($link),
                    'params' => $this->extractListingParams($node->filter('.params')->first()->text()),
                ];
            } catch (\Exception $e) {
                Log::channel(LogChannels::SCRAPING_MOBILE)->error("Failed to scrape mobile.bg ads for $context - {$e->getMessage()}");
            }
        });

        return $results;
    }

    /**
     * @return array<int, array{name: string, slug: null}>
     *
     * @throws ConnectionException
     */
    public function scrapeMakes(): array
    {
        $response = Http::withHeaders($this->browserHeaders())
            ->get('https://www.mobile.bg/');

        if (! $response->successful()) {
            return [];
        }

        $crawler = new Crawler($response->body());
        $makes = [];

        $crawler->filter('#akSearchMarki .a')->each(function (Crawler $node) use (&$makes): void {
            $spans = $node->filter('span');

            if ($spans->count() === 0) {
                return;
            }

            $name = trim($spans->first()->text(''));

            if ($name !== '') {
                $makes[] = ['name' => $name, 'slug' => null];
            }
        });

        return $makes;
    }

    /**
     * Download and parse mobile.bg's car browse-sitemap into a make => [model
     * slugs] map, using mobile.bg's own slug conventions (which differ from the
     * shared catalog). Depth-1 loc URLs are makes; depth-2 are models. The file
     * is gzipped; a live server may also transfer-encode it, so decode falls
     * back to the raw body.
     *
     * @return array<string, list<string>>
     *
     * @throws ConnectionException
     */
    public function fetchMakeModelSlugs(): array
    {
        $response = Http::withHeaders($this->browserHeaders())
            ->get('https://www.mobile.bg/sitemap/sitemap-avtomobili-dzhipove-avtomobili-dzhipove.xml.gz');

        if (! $response->successful()) {
            return [];
        }

        $xml = @gzdecode($response->body());

        if ($xml === false) {
            $xml = $response->body();
        }

        $map = [];

        preg_match_all(
            '#/obiavi/avtomobili-dzhipove/([a-z0-9-]+)(?:/([a-z0-9-]+))?</loc>#i',
            $xml,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $make = $match[1];
            $map[$make] ??= [];

            if (isset($match[2]) && $match[2] !== '') {
                $map[$make][] = $match[2];
            }
        }

        return $map;
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
