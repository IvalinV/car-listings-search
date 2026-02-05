<?php

namespace App\Console\Commands;

use App\Models\CarListing;
use Illuminate\Console\Command;
use PHPUnit\Event\Code\Throwable;

class CleanUpRemovedListingsCommand extends Command
{
    protected $signature = 'listings:clean-up-removed';

    protected $description = 'Clean up all removed listings';

    public function handle(): void
    {
        $this->info('Listings clean up started...');
        CarListing::query()
            ->chunk(1000, function ($carListings) use (&$results) {
                foreach ($carListings as $carListing) {
                    $this->info("Current processing $carListing->title - $carListing->fingerprint");
                    foreach ($carListing->source_urls as $url){
                        try {
                            $removed = \Illuminate\Support\Facades\Http::get($url)->notFound();

                            if ($removed) {
                                $carListing->delete();
                                $this->info("Listing $carListing->fingerprint removed.");
                            }

                            sleep(1);
                        } catch (\Throwable $exception) {
                            \Log::error($exception->getMessage());
                        }
                    }
                }
            });

        $this->info('Listings clean up completed.');
    }
}
