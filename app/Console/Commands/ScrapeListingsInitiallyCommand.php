<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeListingJob;
use App\Services\Scrapers\AutoBgScraper;
use App\Services\Scrapers\Car24Scraper;
use App\Services\Scrapers\CarsBgScraper;
use App\Services\Scrapers\MobileBgScraper;
use Illuminate\Console\Command;

class ScrapeListingsInitiallyCommand extends Command
{
    protected $signature = 'scrape:listings-initially';

    protected $description = 'Scrape as much listings as possible initially';

    private int $maxPages = 100;
    private int $chunkSize = 10;

    private string $type = 'initial';

    public function handle(): void
    {
//        $this->scrapeFromAutoBg();
        $this->scrapeFromCarsBg();
//        $this->scrapeFromCar24();
//        $this->scrapeFromMobileBg();
    }

    protected function scrapeFromAutoBg(): void
    {
        $scraper_class = AutoBgScraper::class;

        $this->scrapeListings($scraper_class, $this->maxPages, $this->chunkSize);
    }

    protected function scrapeFromCarsBg() : void
    {
        $scraper_class = CarsBgScraper::class;

        $this->scrapeListings($scraper_class, $this->maxPages, $this->chunkSize);

    }

    protected function scrapeFromCar24() : void
    {
        $scraper_class = Car24Scraper::class;

        $this->scrapeListings($scraper_class, $this->maxPages, $this->chunkSize);
    }

    protected function scrapeFromMobileBg() : void
    {
        $scraper_class = MobileBgScraper::class;

        $this->scrapeListings($scraper_class, $this->maxPages, $this->chunkSize);
    }

    /**
     * Scrape listing for the provided Scraper Class.
     *
     * @param  string  $scraper_class
     * @param  int  $maxPages
     * @param  int  $chunkSize
     * @return void
     */
    public function scrapeListings(string $scraper_class, int $maxPages, int $chunkSize): void
    {
        $this->info("Dispatching batches of $this->chunkSize for $scraper_class pages...");

        for ($startPage = 1; $startPage <= $maxPages; $startPage += $chunkSize) {
            $endPage = $startPage + ($chunkSize - 1);

            // Dispatch a job for range 1-10, 11-20, etc.
            ScrapeListingJob::dispatch($scraper_class, from_page: $startPage, to_page: $endPage, type: $this->type);

            $this->line("Dispatched batch: Page $startPage to $endPage");
        }

        $this->info('All 10 batch jobs are in the queue.');
    }
}
