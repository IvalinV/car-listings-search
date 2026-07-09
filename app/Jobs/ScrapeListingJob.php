<?php

namespace App\Jobs;

use App\Misc\LogChannels;
use App\Services\ListingPersister;
use App\Services\Scrapers\CarsBgScraper;
use App\Services\Scrapers\Scraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class ScrapeListingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 180;

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function __construct(
        private readonly string $scraper_class,
        private readonly string $from_page,
        private readonly string $to_page,
        private readonly string $type = 'daily'
    ) {}

    public function handle(): void
    {
        $scraper = app($this->scraper_class);
        $results = [];

        for ($i = $this->from_page; $i <= $this->to_page; $i++) {
            $results[] = $this->scraper_class === CarsBgScraper::class && $this->type === 'daily'
                ? $scraper->scrapeNewestListings(page: $i)
                : $scraper->scrape(page: $i);

            sleep(2);
        }

        $results = Arr::collapse($results);

        try {
            $this->persistRecords($results, $scraper);
        } catch (\Throwable $exception) {
            \Log::channel(LogChannels::SCRAPING_JOB)->error("Scraping job exception: {$exception->getMessage()}");
        }
    }

    /**
     * Store the scraped data in the database
     */
    public function persistRecords(array $results, Scraper $scraper): void
    {
        app(ListingPersister::class)->persist($results, $scraper);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        \Log::channel(LogChannels::SCRAPING_JOB)->error("Scraping job exception: {$exception->getMessage()}");
    }
}
