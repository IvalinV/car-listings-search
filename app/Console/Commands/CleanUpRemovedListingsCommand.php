<?php

namespace App\Console\Commands;

use App\Misc\LogChannels;
use App\Models\CarListing;
use App\Services\Scrapers\AutoBgScraper;
use App\Services\Scrapers\Car24Scraper;
use App\Services\Scrapers\CarsBgScraper;
use App\Services\Scrapers\MobileBgScraper;
use App\Services\Scrapers\Scraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanUpRemovedListingsCommand extends Command
{
    protected $signature = 'listings:clean-up-removed';

    protected $description = 'Clean up all removed listings';

    public function handle(): void
    {
        Log::channel(LogChannels::LISTINGS)->info('Listings clean up started...');

        CarListing::query()
            ->chunkById(100, function ($carListings) {
                foreach ($carListings as $carListing) {
                    Log::channel(LogChannels::LISTINGS)->info("Current processing $carListing->title - $carListing->fingerprint");

                    $liveUrls = [];
                    $removedAny = false;

                    foreach ($carListing->source_urls as $url) {
                        try {
                            $scraper = $this->resolveScraper($url);

                            if (! $scraper) {
                                $liveUrls[] = $url;

                                continue;
                            }

                            $removed = $scraper->isListingRemoved($url);

                            if ($removed) {
                                $removedAny = true;
                            } else {
                                $liveUrls[] = $url;
                            }

                            sleep(1);
                        } catch (\Throwable $exception) {
                            $liveUrls[] = $url;
                            Log::channel(LogChannels::LISTINGS)->error($exception->getMessage());
                        }
                    }

                    if ($removedAny && $liveUrls === []) {
                        $carListing->delete();
                        Log::channel(LogChannels::LISTINGS)->info("Listing $carListing->fingerprint removed.");
                    } elseif ($removedAny) {
                        $carListing->update(['source_urls' => $liveUrls]);
                        Log::channel(LogChannels::LISTINGS)->info("Listing $carListing->fingerprint pruned to ".count($liveUrls).' live source(s).');
                    }
                }
            });

        Log::channel(LogChannels::LISTINGS)->info('Listings clean up completed.');
    }

    /**
     * Resolve the scraper responsible for a given listing URL by its host.
     */
    private function resolveScraper(string $url): ?Scraper
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';

        return match (true) {
            str_contains($host, 'mobile.bg') => app(MobileBgScraper::class),
            str_contains($host, 'auto.bg') => app(AutoBgScraper::class),
            str_contains($host, 'car24') => app(Car24Scraper::class),
            str_contains($host, 'cars.bg') => app(CarsBgScraper::class),
            default => null,
        };
    }
}
