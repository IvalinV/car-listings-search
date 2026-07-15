<?php

namespace App\Console\Commands;

use App\Jobs\SweepMobileBgPageJob;
use App\Services\Scrapers\MobileBgScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScrapeMobileBgCatalogCommand extends Command
{
    protected $signature = 'scrape:mobilebg-catalog {--make= : Limit the sweep to a single make slug}';

    protected $description = 'Ingest the full mobile.bg car catalog, segmented per make (descending to model past the page cap)';

    public function handle(MobileBgScraper $scraper): int
    {
        Log::info('mobile.bg catalog sweep started.');

        $slugs = $scraper->fetchMakeModelSlugs();

        if ($slugs === []) {
            $this->error('Could not read the mobile.bg browse-sitemap; aborting.');
            Log::error('mobile.bg catalog sweep aborted: empty slug map.');

            return self::FAILURE;
        }

        $only = $this->option('make');
        $dispatched = 0;

        foreach ($slugs as $make => $models) {
            if ($only !== null && $make !== $only) {
                continue;
            }

            SweepMobileBgPageJob::dispatch($make, 1, array_values($models))->onQueue('scrape-listings');
            $dispatched++;
        }

        $message = "$dispatched mobile.bg make segment job(s) dispatched.";
        $this->info($message);
        Log::info("mobile.bg catalog sweep dispatched: $message");

        return self::SUCCESS;
    }
}
