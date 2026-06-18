<?php

namespace App\Jobs;

use App\Misc\LogChannels;
use App\Models\CarListing;
use App\Services\Deduplication;
use App\Services\Scrapers\CarsBgScraper;
use App\Services\Scrapers\Scraper;
use App\Services\SitemapCache;
use Carbon\Carbon;
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
            $sourceDates = $listing ? ($listing->source_dates ?? []) : [];

            if (! in_array($source_url, $sources)) {
                $sources[] = $source_url;
            }

            $entry = array_filter([
                'created' => $this->toDateTimeString(Arr::get($record, 'published_at')),
                'updated' => $this->toDateTimeString(Arr::get($record, 'updated_at')),
            ]);

            if ($entry !== []) {
                $sourceDates[$record['source']] = $entry;
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
                'source_dates' => json_encode($sourceDates),
                'published_at' => $this->latestUpdate($sourceDates),
            ], 'fingerprint');
        }

        if ($results !== []) {
            SitemapCache::flush();
        }
    }

    /**
     * The most recent update across all sources, used as the `published_at`
     * column ("Обновена"). Each entry is a `{created, updated}` map (legacy
     * rows are a bare date string); the update date falls back to creation.
     */
    private function latestUpdate(array $sourceDates): ?string
    {
        $updates = collect($sourceDates)
            ->map(fn ($entry) => is_array($entry) ? ($entry['updated'] ?? $entry['created'] ?? null) : $entry)
            ->filter();

        return $updates->isEmpty() ? null : $updates->max();
    }

    private function toDateTimeString(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return $value instanceof Carbon ? $value->toDateTimeString() : (string) $value;
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        \Log::channel(LogChannels::SCRAPING_JOB)->error("Scraping job exception: {$exception->getMessage()}");
    }
}
