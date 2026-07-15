<?php

namespace App\Jobs;

use App\Services\ListingPersister;
use App\Services\Scrapers\MobileBgScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scrapes and persists exactly one page of a mobile.bg make (or make/model)
 * segment, then chains the next step itself: the next page while pages keep
 * coming, or — on reaching the page cap — one walker per child model so the
 * make's overflow past mobile.bg's ~151-page result cap is still covered. One
 * bounded page per job replaces the old whole-make loop that always timed out.
 */
class SweepMobileBgPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    /**
     * @param  string  $slug  make slug ("bmw") or make/model slug ("bmw/x5")
     * @param  int  $page  1-based page within this segment
     * @param  list<string>  $childSlugs  model slugs to descend into if this
     *                                    segment reaches the page cap; only set
     *                                    on a make seed, empty on model walkers
     */
    public function __construct(
        public string $slug,
        public int $page = 1,
        public array $childSlugs = [],
    ) {}

    /**
     * Guard against a page running concurrently with itself (e.g. a duplicate
     * chained by an at-least-once redelivery). Keyed per page so distinct pages
     * of the same make still run in parallel. Dropped rather than released so a
     * duplicate never re-queues.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("mbg:{$this->slug}:{$this->page}"))->dontRelease()];
    }

    public function handle(MobileBgScraper $scraper, ListingPersister $persister): void
    {
        $records = $scraper->scrapeSegment($this->slug, $this->page);

        if ($records === []) {
            return;
        }

        $persister->persist($records, $scraper);

        $pageCap = (int) config('listings.mobilebg_sweep.page_cap');

        if ($this->page < $pageCap) {
            self::dispatch($this->slug, $this->page + 1, $this->childSlugs)->onQueue('scrape-listings');

            return;
        }

        if ($this->childSlugs === []) {
            Log::warning("mobile.bg segment {$this->slug} reached the page cap with no models to descend into; coverage may be truncated.");

            return;
        }

        foreach ($this->childSlugs as $child) {
            self::dispatch("{$this->slug}/{$child}", 1, [])->onQueue('scrape-listings');
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error("mobile.bg page sweep failed for {$this->slug} p{$this->page}: {$exception?->getMessage()}");
    }
}
