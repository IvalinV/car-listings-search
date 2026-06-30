<?php

namespace App\Console\Commands;

use App\Misc\LogChannels;
use App\Models\CarMake;
use App\Models\CarModel;
use App\Services\Scrapers\AutoBgScraper;
use App\Services\Scrapers\Car24Scraper;
use App\Services\Scrapers\CarsBgScraper;
use App\Services\Scrapers\MakeNormalizer;
use App\Services\Scrapers\MobileBgScraper;
use App\Services\Scrapers\Scraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ScrapeMakesModelsCommand extends Command
{
    protected $signature = 'scrape:makes-models';

    protected $description = 'Scrape, deduplicate and store car makes (all platforms) and models (auto.bg)';

    /**
     * @var array<int, class-string<Scraper>>
     */
    private array $makeSources = [
        Car24Scraper::class,
        AutoBgScraper::class,
        CarsBgScraper::class,
        MobileBgScraper::class,
    ];

    public function handle(MakeNormalizer $normalizer): int
    {
        $pooled = [];

        foreach ($this->makeSources as $source) {
            try {
                foreach (app($source)->scrapeMakes() as $make) {
                    $pooled[] = $make['name'];
                }
            } catch (\Throwable $e) {
                Log::channel(LogChannels::LISTINGS)->error("Failed to scrape makes from {$source} - {$e->getMessage()}");
            }
        }

        $canonicalNames = $normalizer->dedupe($pooled);

        foreach ($canonicalNames as $name) {
            CarMake::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        }

        $this->info(count($pooled).' makes scraped, '.count($canonicalNames).' after dedupe.');

        $this->scrapeModels($normalizer);

        return self::SUCCESS;
    }

    private function scrapeModels(MakeNormalizer $normalizer): void
    {
        $auto = app(AutoBgScraper::class);

        try {
            $makes = $auto->scrapeMakes();
        } catch (\Throwable $e) {
            Log::channel(LogChannels::LISTINGS)->error("Failed to scrape auto.bg makes for models - {$e->getMessage()}");

            return;
        }

        foreach ($makes as $make) {
            if (empty($make['slug'])) {
                continue;
            }

            $carMake = CarMake::where('name', $normalizer->canonicalize($make['name']))->first();

            if ($carMake === null) {
                continue;
            }

            try {
                $models = $auto->scrapeModels($make['slug']);
            } catch (\Throwable $e) {
                Log::channel(LogChannels::LISTINGS)->error("Failed to scrape auto.bg models for {$make['slug']} - {$e->getMessage()}");

                continue;
            }

            $seen = [];

            foreach ($models as $model) {
                $name = trim(preg_replace('/\s+/u', ' ', $model['name']));

                if ($name === '' || isset($seen[mb_strtolower($name)])) {
                    continue;
                }

                $seen[mb_strtolower($name)] = true;

                CarModel::firstOrCreate(
                    ['car_make_id' => $carMake->id, 'name' => $name],
                    ['slug' => $model['slug'] !== '' ? $model['slug'] : Str::slug($name)],
                );
            }

            $this->line("{$carMake->name}: ".count($seen).' models');
        }
    }
}
