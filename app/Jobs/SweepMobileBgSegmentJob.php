<?php

namespace App\Jobs;

use App\Misc\LogChannels;
use App\Services\ListingPersister;
use App\Services\Scrapers\MobileBgScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    /**
     * Prevent the same segment from running concurrently. A duplicate reserved
     * because the queue's retry_after is shorter than this job's timeout is
     * dropped rather than released, so a segment never double-persists or
     * double-dispatches its model descent. The lock expiry sits above the
     * job timeout so a dead job's lock still clears.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->slug))->dontRelease()->expireAfter(700)];
    }

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

            try {
                $persister->persist($records, $scraper);
            } catch (\Throwable $e) {
                Log::channel(LogChannels::LISTINGS)->error("mobile.bg segment {$this->slug} failed to persist page $page: {$e->getMessage()}");
            }

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
