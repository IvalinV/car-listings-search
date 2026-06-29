<?php

namespace App\Console\Commands;

use App\Models\CarListing;
use App\Services\ListingMakeModelResolver;
use Illuminate\Console\Command;

class ResolveListingMakesModelsCommand extends Command
{
    protected $signature = 'listings:resolve-makes-models';

    protected $description = 'Resolve and store make/model for every listing from its title';

    public function handle(ListingMakeModelResolver $resolver): int
    {
        $processed = 0;
        $noMake = 0;
        $noModel = 0;

        CarListing::query()
            ->whereNull('car_make_id')
            ->chunkById(200, function ($listings) use ($resolver, &$processed, &$noMake, &$noModel): void {
            foreach ($listings as $listing) {
                $resolved = $resolver->resolve($listing->title);

                $listing->update([
                    'car_make_id' => $resolved['make']?->id,
                    'car_model_id' => $resolved['model']?->id,
                ]);

                $processed++;

                if ($resolved['make'] === null) {
                    $noMake++;
                }

                if ($resolved['model'] === null) {
                    $noModel++;
                }
            }
        });

        $this->info("Processed {$processed} listings; {$noMake} without make, {$noModel} without model.");

        return self::SUCCESS;
    }
}
