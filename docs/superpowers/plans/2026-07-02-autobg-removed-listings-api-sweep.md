# auto.bg Removed-Listings API-Diff Sweep — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a daily `listings:sweep-autobg` command that enumerates live auto.bg advert ids from the JSON API and bumps `checked_at` on matching listings, so the hourly probe stops wasting requests on live auto.bg listings and focuses on the removed tail.

**Architecture:** A new scraper primitive `AutoBgScraper::fetchAdvertPage()` pages the JSON search API for any make/model slug. A new command enumerates live `seo_id`s per make (descending to catalog models only when a make's feed is truncated at the API's page cap), then bulk-bumps `checked_at` on auto.bg listings whose `seo_id` is in that live set. The existing hourly `CleanUpRemovedListingsCommand` is unchanged — `checked_at` ordering alone routes live listings to the back of its probe queue and the removed tail to the front.

**Tech Stack:** PHP 8.3, Laravel 12, Pest 4, Laravel HTTP client (`Http::fake` for tests).

## Global Constraints

- PHP 8.3 / Laravel 12 (streamlined structure; commands auto-register from `app/Console/Commands/`).
- Explicit return type declarations on every method; curly braces on all control structures.
- No `env()` outside config files — read tunables via `config('listings.autobg_sweep.*')`.
- Tests are Pest feature tests; fake HTTP with `Http::fake`. Use factories for models.
- Run `vendor/bin/pint --dirty --format agent` before each commit; expect `{"tool":"pint","result":"passed"}`.
- Run tests with `php artisan test --compact`.
- The reconciliation must never delete a listing; it may only set `checked_at`. Removal remains the hourly command's job.
- Match a listing to an advert by the numeric id in its auto.bg URL: `#/obiava/(\d+)#`.

---

## File Structure

- **Modify** `config/listings.php` — add an `autobg_sweep` block (page cap, pause, chunk size, timeouts).
- **Modify** `app/Services/Scrapers/AutoBgScraper.php` — add `fetchAdvertPage(string $path, int $page): array`.
- **Modify** `tests/Feature/Scrapers/AutoBgScraperTest.php` — tests for `fetchAdvertPage`.
- **Create** `app/Console/Commands/SweepAutoBgListingsCommand.php` — the sweep command.
- **Create** `tests/Feature/Commands/SweepAutoBgListingsCommandTest.php` — command behaviour tests.
- **Modify** `routes/console.php` — schedule the command daily.

---

## Task 1: `fetchAdvertPage` API paging primitive

**Files:**
- Modify: `config/listings.php`
- Modify: `app/Services/Scrapers/AutoBgScraper.php`
- Test: `tests/Feature/Scrapers/AutoBgScraperTest.php`

**Interfaces:**
- Consumes: existing `Scraper::browserHeaders()`.
- Produces: `AutoBgScraper::fetchAdvertPage(string $path, int $page): array{ids: list<string>, lastpage: int, ok: bool}` — `$path` is a category-relative slug path such as `"avtomobili-dzhipove/audi"` or `"avtomobili-dzhipove/bmw/320"`. `ids` are `seo_id` strings of `active == 1` adverts on that page; `lastpage` is the API's reported last page; `ok` is `false` on any non-2xx response or connection error.

- [ ] **Step 1: Add the config block**

In `config/listings.php`, add a new key inside the top-level array (a sibling of `cleanup`):

```php
    'autobg_sweep' => [
        // The auto.bg API caps pagination at 100 pages (2000 adverts). This is
        // both the hard page limit and the truncation signal: a slug whose
        // lastpage reports 100 is truncated and must be split by model.
        'page_cap' => 100,

        // Pause between page requests (ms) to bound the sustained request rate.
        'pause_ms' => 150,

        // Listings loaded per reconciliation chunk.
        'chunk_size' => 500,

        // Per-request TCP connect and total timeouts (seconds). A timed-out
        // page surfaces as ok=false and simply ends that segment's paging.
        'connect_timeout' => 10,
        'request_timeout' => 20,
    ],
```

- [ ] **Step 2: Write the failing tests**

Append to `tests/Feature/Scrapers/AutoBgScraperTest.php`:

```php
it('fetches a page of active seo_ids with the reported last page', function (): void {
    Http::fake([
        'www.auto.bg/api/srcresults/*' => Http::response([
            'data' => [
                'lastpage' => 7,
                'adverts' => [
                    ['seo_id' => '111', 'active' => 1],
                    ['seo_id' => '222', 'active' => 0],
                    ['seo_id' => '333', 'active' => 1],
                ],
            ],
        ]),
    ]);

    $result = (new AutoBgScraper)->fetchAdvertPage('avtomobili-dzhipove/audi', 1);

    expect($result['ok'])->toBeTrue()
        ->and($result['lastpage'])->toBe(7)
        ->and($result['ids'])->toBe(['111', '333']); // inactive 222 skipped
});

it('reports ok=false when the advert page request fails', function (): void {
    Http::fake(['www.auto.bg/api/srcresults/*' => Http::response('', 500)]);

    $result = (new AutoBgScraper)->fetchAdvertPage('avtomobili-dzhipove/audi', 1);

    expect($result)->toBe(['ids' => [], 'lastpage' => 0, 'ok' => false]);
});

it('reports ok=false when the advert page connection fails', function (): void {
    Http::fake(['www.auto.bg/api/srcresults/*' => fn () => throw new ConnectionException('timed out')]);

    expect((new AutoBgScraper)->fetchAdvertPage('avtomobili-dzhipove/audi', 1)['ok'])->toBeFalse();
});
```

Ensure the test file imports `ConnectionException` at the top (add if missing):

```php
use Illuminate\Http\Client\ConnectionException;
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact --filter="fetches a page|reports ok=false"`
Expected: FAIL — `Call to undefined method App\Services\Scrapers\AutoBgScraper::fetchAdvertPage()`.

- [ ] **Step 4: Implement `fetchAdvertPage`**

In `app/Services/Scrapers/AutoBgScraper.php`, add this method (e.g. directly after `mapAdvert()`):

```php
    /**
     * Fetch one page of the auto.bg JSON search API for a category-relative
     * path (e.g. "avtomobili-dzhipove/audi") and return the seo_ids of active
     * adverts on that page, the reported last page, and whether the request
     * succeeded. A non-2xx response or connection error yields ok=false.
     *
     * @return array{ids: list<string>, lastpage: int, ok: bool}
     */
    public function fetchAdvertPage(string $path, int $page): array
    {
        $slug = "/$path/page/$page";

        try {
            $response = Http::withHeaders([
                ...$this->browserHeaders(),
                'Accept' => 'application/json, text/plain, */*',
                'Referer' => 'https://www.auto.bg/obiavi/'.$path,
            ])
                ->connectTimeout((int) config('listings.autobg_sweep.connect_timeout'))
                ->timeout((int) config('listings.autobg_sweep.request_timeout'))
                ->get("https://www.auto.bg/api/srcresults/$page", ['slug' => $slug]);
        } catch (ConnectionException) {
            return ['ids' => [], 'lastpage' => 0, 'ok' => false];
        }

        if (! $response->successful()) {
            return ['ids' => [], 'lastpage' => 0, 'ok' => false];
        }

        $ids = [];

        foreach ((array) $response->json('data.adverts', []) as $advert) {
            if ((int) ($advert['active'] ?? 0) === 1 && ! empty($advert['seo_id'])) {
                $ids[] = (string) $advert['seo_id'];
            }
        }

        return ['ids' => $ids, 'lastpage' => (int) $response->json('data.lastpage', 1), 'ok' => true];
    }
```

`ConnectionException` is already imported in this file (used by `scrape()`); no new import needed.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter="fetches a page|reports ok=false|connection fails"`
Expected: PASS (3 tests).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/listings.php app/Services/Scrapers/AutoBgScraper.php tests/Feature/Scrapers/AutoBgScraperTest.php
git commit -m "feat: add auto.bg API page-enumeration primitive"
```

---

## Task 2: `listings:sweep-autobg` command (enumerate + reconcile)

**Files:**
- Create: `app/Console/Commands/SweepAutoBgListingsCommand.php`
- Test: `tests/Feature/Commands/SweepAutoBgListingsCommandTest.php`
- Modify: `routes/console.php`

**Interfaces:**
- Consumes: `AutoBgScraper::fetchAdvertPage(string $path, int $page): array{ids: list<string>, lastpage: int, ok: bool}` (Task 1); `CarMake` (`id`, `slug`, `models()`); `CarModel` (`car_make_id`, `slug`); `CarListing` (`source_urls` array cast, `checked_at`); `config('listings.autobg_sweep.*')`.
- Produces: artisan command `listings:sweep-autobg` returning `self::SUCCESS`; sets `checked_at = now()` on auto.bg listings whose `seo_id` is live. No other side effects.

- [ ] **Step 1: Generate the command file**

Run: `php artisan make:command SweepAutoBgListingsCommand --no-interaction`
Expected: creates `app/Console/Commands/SweepAutoBgListingsCommand.php`.

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/Commands/SweepAutoBgListingsCommandTest.php`:

```php
<?php

use App\Models\CarListing;
use App\Models\CarMake;
use App\Models\CarModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function autobgListing(string $seoId, ?string $checkedAt = null): CarListing
{
    return CarListing::factory()->create([
        'source_urls' => ["https://www.auto.bg/obiava/$seoId/some-car"],
        'checked_at' => $checkedAt,
    ]);
}

it('bumps checked_at for listings whose seo_id is live and leaves absent ones untouched', function (): void {
    CarMake::factory()->create(['slug' => 'audi']);

    Http::fake([
        'www.auto.bg/api/srcresults/*' => Http::response([
            'data' => ['lastpage' => 1, 'adverts' => [['seo_id' => '111', 'active' => 1]]],
        ]),
    ]);

    $live = autobgListing('111');
    $dead = autobgListing('999');

    $this->artisan('listings:sweep-autobg')->assertSuccessful();

    expect($live->fresh()->checked_at)->not->toBeNull()
        ->and($dead->fresh()->checked_at)->toBeNull();
});

it('descends into catalog models when a make feed is truncated at the page cap', function (): void {
    $make = CarMake::factory()->create(['slug' => 'bmw']);
    CarModel::factory()->create(['car_make_id' => $make->id, 'slug' => '320']);

    Http::fake(function (Request $request) {
        $url = urldecode($request->url());

        if (str_contains($url, '/bmw/320/page')) {
            return Http::response(['data' => ['lastpage' => 1, 'adverts' => [['seo_id' => '222', 'active' => 1]]]]);
        }

        if (str_contains($url, '/bmw/page')) {
            return Http::response(['data' => ['lastpage' => 100, 'adverts' => [['seo_id' => '111', 'active' => 1]]]]);
        }

        return Http::response(['data' => ['lastpage' => 1, 'adverts' => []]]);
    });

    $fromMakePage = autobgListing('111');   // present on the truncated make's first page
    $fromModel = autobgListing('222');       // only reachable via model descent

    $this->artisan('listings:sweep-autobg')->assertSuccessful();

    expect($fromMakePage->fresh()->checked_at)->not->toBeNull()
        ->and($fromModel->fresh()->checked_at)->not->toBeNull();
});

it('does not bump when the make feed request fails', function (): void {
    CarMake::factory()->create(['slug' => 'audi']);

    Http::fake(['www.auto.bg/api/srcresults/*' => Http::response('', 500)]);

    $listing = autobgListing('111');

    $this->artisan('listings:sweep-autobg')->assertSuccessful();

    expect($listing->fresh()->checked_at)->toBeNull();
});

it('ignores listings with no auto.bg source url', function (): void {
    CarMake::factory()->create(['slug' => 'audi']);

    Http::fake([
        'www.auto.bg/api/srcresults/*' => Http::response([
            'data' => ['lastpage' => 1, 'adverts' => [['seo_id' => '111', 'active' => 1]]],
        ]),
    ]);

    $other = CarListing::factory()->create([
        'source_urls' => ['https://www.mobile.bg/obiava-111-x'],
        'checked_at' => null,
    ]);

    $this->artisan('listings:sweep-autobg')->assertSuccessful();

    expect($other->fresh()->checked_at)->toBeNull();
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Commands/SweepAutoBgListingsCommandTest.php`
Expected: FAIL — command signature is still the generated default (`app:sweep-auto-bg-listings-command`), so `listings:sweep-autobg` is not found.

- [ ] **Step 4: Implement the command**

Replace the contents of `app/Console/Commands/SweepAutoBgListingsCommand.php` with:

```php
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

        CarListing::query()
            ->where('source_urls', 'like', '%auto.bg%')
            ->select(['id', 'source_urls'])
            ->chunkById((int) config('listings.autobg_sweep.chunk_size'), function (Collection $listings) use ($live, &$bumped): void {
                $ids = [];

                foreach ($listings as $listing) {
                    foreach ($listing->source_urls as $url) {
                        if (preg_match('#/obiava/(\d+)#', (string) $url, $matches) && isset($live[$matches[1]])) {
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
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Commands/SweepAutoBgListingsCommandTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Schedule the command daily**

In `routes/console.php`, add the import alongside the others:

```php
use App\Console\Commands\SweepAutoBgListingsCommand;
```

and add the schedule line after the `CleanUpRemovedListingsCommand` line:

```php
Schedule::command(SweepAutoBgListingsCommand::class)->daily()->withoutOverlapping();
```

- [ ] **Step 7: Verify registration and full suite**

Run: `php artisan schedule:list`
Expected: an entry for `listings:sweep-autobg` running daily.

Run: `php artisan test --compact`
Expected: whole suite green (no regressions in the scraper or clean-up tests).

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/SweepAutoBgListingsCommand.php tests/Feature/Commands/SweepAutoBgListingsCommandTest.php routes/console.php
git commit -m "feat: add daily auto.bg live-id sweep to reduce removal-probe load"
```

---

## Self-Review Notes

- **Spec coverage:** Enumeration with truncation descent (Task 2, `enumerateLiveIds`); presence-only reconciliation that bumps `checked_at` and never deletes (Task 2, `reconcile`); partial-segment safety (Task 2, `pageInclusive` stops on failure, tested by the "make feed request fails" case); config block (Task 1); daily schedule (Task 2, Step 6); hourly command left unchanged (no task touches it). All spec sections mapped.
- **Residual behaviours (by design, from the spec):** a make truncated at the cap with no catalog models keeps only its first ≤2000 ids; the remainder falls to the hourly probe. A model itself exceeding 2000 leaves a tail to the probe. Both are safe (no false removals) and self-correcting.
- **Type consistency:** `fetchAdvertPage` returns `array{ids, lastpage, ok}` everywhere it is consumed; the live set is `array<string, true>` throughout; `seo_id`s are strings on both the API side and the `#/obiava/(\d+)#` match side.
