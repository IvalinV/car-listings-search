<?php

namespace App\Jobs;

use App\Misc\LogChannels;
use App\Models\CarListing;
use App\Services\Scrapers\CarsBgScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class ScrapeListingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $scraper;

    public function __construct(
        private readonly string $scraper_class, private readonly string $from_page, private readonly string $to_page, private readonly string $type = 'daily'
    ) {
        $this->scraper = app($scraper_class);
    }

    public function handle(): void
    {
        $results = [];

        for ($i = $this->from_page; $i <= $this->to_page; $i++) {
            $results[] = $this->scraper_class === CarsBgScraper::class && $this->type === 'daily'
                ? $this->scraper->scrapeDailyResults(page: $i)
                : $this->scraper->scrape(page: $i);
        }

        $results = Arr::collapse($results);

        try {
            $this->persistRecords($results);
        } catch (\Throwable $exception) {
            \Log::channel(LogChannels::SCRAPING_JOB)->error("Scraping job exception: {$exception->getMessage()}");
        }
    }

    /**
     * Store the scraped data in the database
     *
     * @param  array  $results
     * @return void
     */
    public function persistRecords(array $results) : void
    {
        foreach ($results as $record) {
            $mileage = Arr::get($record, 'params.mileage');
            $year = Arr::get($record, 'params.production_year');
            $fuel_type = Arr::get($record, 'params.fuel');
            $source_url = $this->scraper->formatListingUrl(Arr::get($record, 'link'));
            $price = $record['source'] !== 'car24.bg'
                ? $this->scraper->extractPrice(Arr::get($record, 'price'))['eur']
                : $record['price'];

            $uuid = hash('sha256', "$mileage $year $fuel_type");

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
                'source_urls' => json_encode([$source_url]),
            ], 'fingerprint');
        }
    }
}
