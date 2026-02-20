<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeListingJob;
use App\Services\Scrapers\AutoBgScraper;
use App\Services\Scrapers\Car24Scraper;
use App\Services\Scrapers\CarsBgScraper;
use App\Services\Scrapers\MobileBgScraper;
use Illuminate\Console\Command;

class ScrapeNewListingsCommand extends Command
{
    protected $signature = 'scrape:new-listings';

    protected $description = 'Scrape new listings';

    protected string $type = 'daily';

    private int $maxPages = 10;
    private int $chunkSize = 1;

    public function handle(): void
    {
        $this->scrapeCarsBg();

        $this->scrapeAutoBg();

        $this->scrapeMobileBg();

        $this->scrapeCar24();
    }

    public function scrapeAutoBg(): void
    {
        $this->scrapeDailyListings(AutoBgScraper::class);
    }

    public function scrapeCar24(): void
    {
        $this->scrapeDailyListings(Car24Scraper::class);
    }

    public function scrapeCarsBg(): void
    {
        $this->scrapeDailyListings(CarsBgScraper::class);
    }

    public function scrapeMobileBg(): void
    {
        $this->scrapeDailyListings(MobileBgScraper::class);
    }

    /**
     * Scrape newest results.
     */
    public function scrapeDailyListings(string $scraper_class): void
    {
        $this->info("Dispatching batches for newest listings of $this->chunkSize for $scraper_class pages...");

        for ($startPage = 1; $startPage <= $this->maxPages; $startPage += $this->chunkSize) {
            $endPage = $startPage + ($this->chunkSize - 1);

            // Dispatch a job for range 1-10, 11-20, etc.
            ScrapeListingJob::dispatch($scraper_class, from_page: $startPage, to_page: $endPage, type: $this->type);

            $this->line("Dispatched newest listing batch: Page $startPage to $endPage");
        }

        $this->info('All Daily 10 batch jobs are in the queue.');
    }
}
