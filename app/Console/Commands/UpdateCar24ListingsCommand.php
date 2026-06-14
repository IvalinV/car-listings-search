<?php

namespace App\Console\Commands;

use App\Misc\LogChannels;
use App\Models\CarListing;
use App\Services\Scrapers\Car24Scraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateCar24ListingsCommand extends Command
{
    protected $signature = 'update:car24-listings';

    protected $description = 'Update car24 listings which does not have the proper images';

    public function handle(): void
    {
        Log::channel(LogChannels::LISTINGS)->info('Updating Car24 Listings started...');
        $scraper = app(Car24Scraper::class);

        CarListing::where('image_url', 'https://photos.car24.bg/assets/images/nophoto_490x341.svg')
            ->chunkById(100, function ($listings) use ($scraper) {
                foreach ($listings as $listing) {
                    Log::channel(LogChannels::LISTINGS)->info("Currently processing $listing->id listing");
                    $url = \Arr::first($listing->source_urls, fn ($record) => \Str::contains($record, 'car24.bg'));

                    try {
                        $data = $scraper->getListing($url);

                        if (is_null($data)) {
                            Log::channel(LogChannels::LISTINGS)->info("Listing $listing->id not found");
                            $listing->delete();

                            continue;
                        }

                        $image = \Arr::first($data['bigPics']);
                        if ($image != $listing->image_url) {
                            $listing->update(['image_url' => $image]);
                            Log::channel(LogChannels::LISTINGS)->info("Listing $listing->id updated with $image");
                        }
                    } catch (\Throwable $exception) {
                        Log::channel(LogChannels::LISTINGS)->error("Failed to update Car24 listing $listing->id - {$exception->getMessage()}");
                    }
                }
            });

        Log::channel(LogChannels::LISTINGS)->info('Updating Car24 Listings ended...');
    }
}
