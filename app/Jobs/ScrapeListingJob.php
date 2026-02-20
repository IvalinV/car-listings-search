<?php

namespace App\Jobs;

use App\Misc\LogChannels;
use App\Models\CarListing;
use App\Services\Deduplication;
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
                ? $scraper->scrapeDailyResults(page: $i)
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
        foreach ($results as $record) {
            $mileage = Arr::get($record, 'params.mileage');
            $year = Arr::get($record, 'params.production_year');
            $fuel_type = Arr::get($record, 'params.fuel');
            $source_url = $scraper->formatListingUrl(Arr::get($record, 'link'));
            $price = $record['source'] !== 'car24.bg'
                ? $scraper->extractPrice(Arr::get($record, 'price'))['eur']
                : $record['price'];

            $image_url = Arr::get($record, 'image');

            $uuid = Deduplication::make($image_url, [
                'mileage' => $mileage, 'year' => $year, 'fuel_type' => $fuel_type, 'price' => $price,
            ]);

            $listing = CarListing::where('fingerprint', $uuid)->first();
            $sources = $listing ? $listing->source_urls : [];

            if (! in_array($source_url, $sources)) {
                $sources[] = $source_url;
            }

            CarListing::upsert([
                'fingerprint' => $uuid,
                'title' => Arr::get($record, 'title'),
                'price' => $price ?? 0,
                'description' => Arr::get($record, 'description'),
                'year' => $year,
                'fuel_type' => $fuel_type,
                'mileage' => $mileage,
                'location' => Arr::get($record, 'location'),
                'transmission' => Arr::get($record, 'params.transmission'),
                'image_url' => $image_url,
                'source_urls' => json_encode($sources),
            ], 'fingerprint');
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        \Log::channel(LogChannels::SCRAPING_JOB)->error("Scraping job exception: {$exception->getMessage()}");
    }
}
