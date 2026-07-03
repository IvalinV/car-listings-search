<?php

namespace App\Console\Commands;

use App\Misc\LogChannels;
use App\Models\CarListing;
use App\Models\CarMake;
use App\Models\CarModel;
use App\Services\Scrapers\AutoBgScraper;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class SweepAutoBgListingsCommand extends Command
{
    protected $signature = 'listings:sweep-autobg';

    protected $description = 'Enumerate live auto.bg adverts via the JSON API and mark matching listings freshly verified';

    public function handle(AutoBgScraper $scraper): int
    {
        Log::channel(LogChannels::LISTINGS)->info('auto.bg sweep started.');

        $live = $this->enumerateLiveIds($scraper);
        $bumped = $this->reconcile($live);

        $message = count($live).' live auto.bg ids enumerated, '.$bumped.' listing(s) marked verified.';
        $this->info($message);
        Log::channel(LogChannels::LISTINGS)->info("auto.bg sweep completed: $message");

        return self::SUCCESS;
    }

    /**
     * Build the set of currently-live auto.bg advert seo_ids by paging the JSON
     * API per make, descending into catalog models only when a make's feed is
     * truncated at the page cap. Keyed by seo_id for O(1) membership.
     *
     * @return array<string, true>
     */
    private function enumerateLiveIds(AutoBgScraper $scraper): array
    {
        $pageCap = (int) config('listings.autobg_sweep.page_cap');
        $pauseMs = (int) config('listings.autobg_sweep.pause_ms');
        $live = [];

        foreach (CarMake::query()->select(['id', 'slug'])->get() as $make) {
            $makePath = "avtomobili-dzhipove/{$make->slug}";
            $first = $scraper->fetchAdvertPage($makePath, 1);

            if (! $first['ok']) {
                continue;
            }

            $this->merge($live, $first['ids']);

            if ($first['lastpage'] >= $pageCap) {
                $models = CarModel::query()->where('car_make_id', $make->id)->pluck('slug');

                if ($models->isNotEmpty()) {
                    foreach ($models as $modelSlug) {
                        $this->collectPath($live, $scraper, "$makePath/$modelSlug", $pageCap, $pauseMs);
                    }

                    continue;
                }
            }

            $this->pageInclusive($live, $scraper, $makePath, 2, min($first['lastpage'], $pageCap), $pauseMs);
        }

        return $live;
    }

    /**
     * Page a slug path from page 1 to its reported last page (capped), merging
     * every active seo_id into the live set.
     *
     * @param  array<string, true>  $live
     */
    private function collectPath(array &$live, AutoBgScraper $scraper, string $path, int $pageCap, int $pauseMs): void
    {
        $first = $scraper->fetchAdvertPage($path, 1);

        if (! $first['ok']) {
            return;
        }

        $this->merge($live, $first['ids']);
        $this->pageInclusive($live, $scraper, $path, 2, min($first['lastpage'], $pageCap), $pauseMs);
    }

    /**
     * Page a slug path across an inclusive page range, merging active seo_ids.
     * Stops at the first failed page (its listings fall to the hourly probe).
     *
     * @param  array<string, true>  $live
     */
    private function pageInclusive(array &$live, AutoBgScraper $scraper, string $path, int $from, int $to, int $pauseMs): void
    {
        for ($page = $from; $page <= $to; $page++) {
            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }

            $result = $scraper->fetchAdvertPage($path, $page);

            if (! $result['ok']) {
                return;
            }

            $this->merge($live, $result['ids']);
        }
    }

    /**
     * @param  array<string, true>  $live
     * @param  list<string>  $ids
     */
    private function merge(array &$live, array $ids): void
    {
        foreach ($ids as $id) {
            $live[$id] = true;
        }
    }

    /**
     * Bump checked_at on every auto.bg listing whose seo_id is in the live set.
     * Never deletes — removal stays with the hourly clean-up command.
     *
     * @param  array<string, true>  $live
     */
    private function reconcile(array $live): int
    {
        if ($live === []) {
            return 0;
        }

        $bumped = 0;

        // Walk the whole table by primary key (a clean index scan) rather than
        // filtering source_urls in SQL: `source_urls` is a Postgres `json`
        // column, so a `LIKE` on it both errors on Postgres and, when cast,
        // forces a full seq-scan-and-sort per chunk. Matching the auto.bg host
        // and seo_id in PHP keeps each chunk an indexed range read.
        CarListing::query()
            ->select(['id', 'source_urls'])
            ->chunkById((int) config('listings.autobg_sweep.chunk_size'), function (Collection $listings) use ($live, &$bumped): void {
                $ids = [];

                foreach ($listings as $listing) {
                    foreach ($listing->source_urls as $url) {
                        $url = (string) $url;

                        if (str_contains($url, 'auto.bg') && preg_match('#/obiava/(\d+)#', $url, $matches) && isset($live[$matches[1]])) {
                            $ids[] = $listing->id;

                            break;
                        }
                    }
                }

                if ($ids !== []) {
                    $bumped += CarListing::query()->whereIn('id', $ids)->update(['checked_at' => now()]);
                }
            });

        return $bumped;
    }
}
