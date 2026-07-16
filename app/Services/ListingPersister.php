<?php

namespace App\Services;

use App\Models\CarListing;
use App\Services\Scrapers\Scraper;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class ListingPersister
{
    public function persist(array $records, Scraper $scraper): void
    {
        foreach ($records as $record) {
            $mileage = Arr::get($record, 'params.mileage');
            $year = Arr::get($record, 'params.production_year');
            $fuel_type = Arr::get($record, 'params.fuel');
            $source_url = $scraper->formatListingUrl(Arr::get($record, 'link'));
            $price = $record['source'] !== 'car24.bg'
                ? $scraper->extractPrice(Arr::get($record, 'price'))['eur']
                : $record['price'];
            $price = CarListing::normalizePrice($price);

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

            $resolved = app(ListingMakeModelResolver::class)->resolve(Arr::get($record, 'title'));

            CarListing::upsert([
                'fingerprint' => $uuid,
                'title' => Arr::get($record, 'title'),
                'car_make_id' => $resolved['make']?->id,
                'car_model_id' => $resolved['model']?->id,
                'price' => $price,
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
                'checked_at' => now(),
            ], 'fingerprint');
        }

        if ($records !== []) {
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
}
