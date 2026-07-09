<?php

namespace App\Jobs;

use App\Misc\LogChannels;
use App\Services\ListingPersister;
use App\Services\Scrapers\MobileBgScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SweepMobileBgSegmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    /**
     * @param  list<string>  $childSlugs  model slugs to descend into if this
     *                                    segment reaches the page cap
     */
    public function __construct(
        public string $slug,
        public array $childSlugs = [],
    ) {}

    public function handle(MobileBgScraper $scraper, ListingPersister $persister): void
    {
        $pageCap = (int) config('listings.mobilebg_sweep.page_cap');
        $pauseMs = (int) config('listings.mobilebg_sweep.pause_ms');
        $reachedCap = false;

        for ($page = 1; $page <= $pageCap; $page++) {
            $records = $scraper->scrapeSegment($this->slug, $page);

            if ($records === []) {
                break;
            }

            $persister->persist($records, $scraper);

            if ($page === $pageCap) {
                $reachedCap = true;
            }

            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        if (! $reachedCap) {
            return;
        }

        if ($this->childSlugs === []) {
            Log::channel(LogChannels::LISTINGS)->warning("mobile.bg segment {$this->slug} reached the page cap with no models to descend into; coverage may be truncated.");

            return;
        }

        foreach ($this->childSlugs as $child) {
            self::dispatch("{$this->slug}/{$child}")->onQueue('scrape-listings');
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel(LogChannels::LISTINGS)->error("mobile.bg segment sweep failed for {$this->slug}: {$exception?->getMessage()}");
    }
}
