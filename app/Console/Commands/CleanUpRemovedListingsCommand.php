<?php

namespace App\Console\Commands;

use App\Misc\LogChannels;
use App\Models\CarListing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CleanUpRemovedListingsCommand extends Command
{
    protected $signature = 'listings:clean-up-removed';

    protected $description = 'Clean up all removed listings';

    public function handle(): void
    {
        Log::channel(LogChannels::LISTINGS)->info('Listings clean up started...');
        CarListing::query()
            ->chunkbyId(100, function ($carListings) use (&$results) {
                foreach ($carListings as $carListing) {
                    Log::channel(LogChannels::LISTINGS)->info("Current processing $carListing->title - $carListing->fingerprint");
                    foreach ($carListing->source_urls as $url){
                        try {
                            $removed = Http::get($url)->notFound();

                            if ($removed) {
                                $carListing->delete();
                                Log::channel(LogChannels::LISTINGS)->info("Listing $carListing->fingerprint removed.");
                            }

                            sleep(1);
                        } catch (\Throwable $exception) {
                            Log::channel(LogChannels::LISTINGS)->error($exception->getMessage());
                        }
                    }
                }
            });

        Log::channel(LogChannels::LISTINGS)->info('Listings clean up completed.');
    }
}
