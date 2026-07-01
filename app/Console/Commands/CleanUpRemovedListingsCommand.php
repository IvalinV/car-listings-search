<?php

namespace App\Console\Commands;

use App\Models\CarListing;
use App\Services\Scrapers\AutoBgScraper;
use App\Services\Scrapers\Car24Scraper;
use App\Services\Scrapers\CarsBgScraper;
use App\Services\Scrapers\MobileBgScraper;
use App\Services\Scrapers\Scraper;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class CleanUpRemovedListingsCommand extends Command
{
    protected $signature = 'listings:clean-up-removed {--limit=}';

    protected $description = 'Probe the least-recently-verified listings and remove those gone from all sources';

    public function handle(): void
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('listings.cleanup.batch_limit')));
        $concurrency = max(1, (int) config('listings.cleanup.pool_concurrency'));
        $pauseMs = max(0, (int) config('listings.cleanup.pool_pause_ms'));

        $this->info("Listings clean up started (limit $limit).");

        try {
            $listings = CarListing::query()
                ->orderByRaw('checked_at ASC NULLS FIRST')
                ->limit($limit)
                ->get();

            $probes = $this->buildProbes($listings);
            $classifications = $this->classifyAll($probes, $concurrency, $pauseMs);

            foreach ($listings as $listing) {
                $this->resolveListing($listing, $classifications[$listing->id] ?? []);
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }

        $this->info('Listings clean up completed.');
    }

    /**
     * Flatten listings into per-URL probes, interleaved by source so each pool
     * chunk is spread across hosts. A URL with no known scraper is left out of
     * the probes (and so kept as a live source — never blocks on a probe).
     *
     * @param  Collection<int, CarListing>  $listings
     * @return list<array{listing_id:int,url:string,scraper:Scraper}>
     */
    private function buildProbes(Collection $listings): array
    {
        $byHost = [];

        foreach ($listings as $listing) {
            foreach ($listing->source_urls as $url) {
                $scraper = $this->resolveScraper($url);

                if (! $scraper) {
                    continue;
                }

                $byHost[$scraper::class][] = ['listing_id' => $listing->id, 'url' => $url, 'scraper' => $scraper];
            }
        }

        return $this->interleave($byHost);
    }

    /**
     * Round-robin the per-host queues so each fixed-size pool chunk is spread
     * across hosts rather than hitting one host with a dense burst. Sustained
     * request rate is bounded separately by the inter-chunk pause in classifyAll().
     *
     * @param  array<string, list<array{listing_id:int,url:string,scraper:Scraper}>>  $byHost
     * @return list<array{listing_id:int,url:string,scraper:Scraper}>
     */
    private function interleave(array $byHost): array
    {
        $queues = array_values($byHost);
        $interleaved = [];
        $remaining = true;

        while ($remaining) {
            $remaining = false;

            foreach ($queues as &$queue) {
                if ($queue !== []) {
                    $interleaved[] = array_shift($queue);
                    $remaining = true;
                }
            }
            unset($queue);
        }

        return $interleaved;
    }

    /**
     * Probe every URL in concurrency-capped pool chunks and classify each as
     * 'removed' | 'alive' | 'unknown', grouped by listing id and keyed by url.
     *
     * @param  list<array{listing_id:int,url:string,scraper:Scraper}>  $probes
     * @return array<int, array<string, string>>
     */
    private function classifyAll(array $probes, int $concurrency, int $pauseMs): array
    {
        $result = [];
        $chunks = array_chunk($probes, $concurrency);
        $lastChunk = count($chunks) - 1;

        foreach ($chunks as $index => $chunk) {
            // Http::pool keys results by the Pool::as() key; the closure's return
            // value is ignored, so each probe is registered under its chunk index.
            $responses = Http::pool(function (Pool $pool) use ($chunk): void {
                foreach ($chunk as $i => $probe) {
                    $probe['scraper']->poolRemovalProbe($pool->as((string) $i), $probe['url']);
                }
            });

            foreach ($chunk as $i => $probe) {
                $result[$probe['listing_id']][$probe['url']] = $this->classify(
                    $responses[(string) $i] ?? null,
                    $probe['scraper'],
                    $probe['url'],
                );
            }

            if ($pauseMs > 0 && $index < $lastChunk) {
                usleep($pauseMs * 1000);
            }
        }

        return $result;
    }

    /**
     * Transient or blocked responses (connection error, 5xx, 429, 403) are
     * 'unknown' and never treated as removed, so the listing is retried rather
     * than falsely refreshed; otherwise the scraper interprets the response.
     */
    private function classify(mixed $response, Scraper $scraper, string $url): string
    {
        if (! $response instanceof Response) {
            return 'unknown';
        }

        if ($response->serverError() || $response->tooManyRequests() || $response->forbidden()) {
            return 'unknown';
        }

        return $scraper->isRemovedFromResponse($response, $url) ? 'removed' : 'alive';
    }

    /**
     * Apply the per-listing decision: skip on any unknown, delete when all
     * removed, prune when partial, bump checked_at when alive.
     *
     * @param  array<string, string>  $classByUrl
     */
    private function resolveListing(CarListing $listing, array $classByUrl): void
    {
        if (in_array('unknown', $classByUrl, true)) {
            $this->info("Listing $listing->fingerprint skipped (transient).");

            return;
        }

        $liveUrls = [];

        foreach ($listing->source_urls as $url) {
            if (($classByUrl[$url] ?? 'alive') !== 'removed') {
                $liveUrls[] = $url;
            }
        }

        if ($liveUrls === []) {
            $listing->delete();
            $this->info("Listing $listing->fingerprint removed.");

            return;
        }

        $update = ['checked_at' => now()];

        if (count($liveUrls) !== count($listing->source_urls)) {
            $update['source_urls'] = $liveUrls;
            $this->info("Listing $listing->fingerprint pruned to ".count($liveUrls).' live source(s).');
        }

        $listing->update($update);
    }

    /**
     * Resolve the scraper responsible for a given listing URL by its host.
     */
    private function resolveScraper(string $url): ?Scraper
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';

        return match (true) {
            str_contains($host, 'mobile.bg') => app(MobileBgScraper::class),
            str_contains($host, 'auto.bg') => app(AutoBgScraper::class),
            str_contains($host, 'car24') => app(Car24Scraper::class),
            str_contains($host, 'cars.bg') => app(CarsBgScraper::class),
            default => null,
        };
    }
}
