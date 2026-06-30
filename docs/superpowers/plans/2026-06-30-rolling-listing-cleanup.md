# Rolling Inactive-Listing Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reliably delete listings removed from all their sources (and prune dead URLs from partially-removed multi-source listings) via a bounded, rolling, self-healing sweep that always completes.

**Architecture:** Add a `checked_at` "last confirmed alive" timestamp to `car_listings`. The scrape stamps it for free on every live listing it touches. A rewritten `listings:clean-up-removed` command takes the N oldest-`checked_at` listings each run, probes their source URLs concurrently via `Http::pool`, and hard-deletes only when every source confirms removed — transient errors are skipped and retried. Scheduled hourly instead of weekly.

**Tech Stack:** Laravel 12, PHP 8.3, PostgreSQL (prod) / SQLite (tests), Pest 4, Laravel HTTP client (`Http::pool`).

## Global Constraints

- Removal is a **hard `delete()`** — no soft delete / `is_active` change.
- Per-source detection logic (mobile.bg 404, cars.bg `status_page.php`, auto.bg `/obiavi/`, car24 API `advert` null) is **unchanged**; existing `isListingRemoved` methods and their tests stay intact.
- A listing is deleted **only** when every source independently confirms removed; **transient errors (5xx / 429 / connection failure) never count as removed**.
- `source_urls` is a **numeric JSON list of full URLs** (production shape) — prune by rebuilding a numeric list.
- Config values read via `config()`, never `env()` outside config files.
- Run `vendor/bin/pint --dirty --format agent` before each commit.
- Tests must not hit the real network — use `Http::fake`.
- Ordering must be portable across Postgres and SQLite: `orderByRaw('checked_at IS NULL DESC')->orderBy('checked_at')` (NULLs first in both).

---

### Task 1: `checked_at` column + scrape liveness stamping

Adds the timestamp and makes the existing scrape stamp it, so freshly-seen listings are pushed to the back of the sweep queue for free.

**Files:**
- Create: `database/migrations/2026_06_30_000001_add_checked_at_to_car_listings_table.php`
- Modify: `app/Jobs/ScrapeListingJob.php` (the `upsert(...)` array in `persistRecords`, ~line 99-114)
- Test: `tests/Feature/Jobs/ScrapeListingJobTest.php` (append)

**Interfaces:**
- Produces: `car_listings.checked_at` (nullable timestamp, indexed). After a scrape upsert, every touched listing has `checked_at = now()`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Jobs/ScrapeListingJobTest.php`:

```php
it('stamps checked_at on a listing the scrape touches', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-30 12:00:00'));

    persist([scrapedRecord('mobile.bg', 'www.mobile.bg/obiava-11572426012950088-audi-a4', Carbon::parse('2019-10-30 09:00:12'))]);

    expect(CarListing::first()->checked_at->toDateTimeString())->toBe('2026-06-30 12:00:00');
});

it('refreshes checked_at when an existing listing is re-scraped', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-30 12:00:00'));
    persist([scrapedRecord('mobile.bg', 'www.mobile.bg/obiava-11572426012950088-audi-a4', Carbon::parse('2019-10-30 09:00:12'))]);

    Carbon::setTestNow(Carbon::parse('2026-07-01 08:00:00'));
    persist([scrapedRecord('mobile.bg', 'www.mobile.bg/obiava-11572426012950088-audi-a4', Carbon::parse('2019-10-30 09:00:12'))]);

    expect(CarListing::count())->toBe(1)
        ->and(CarListing::first()->checked_at->toDateTimeString())->toBe('2026-07-01 08:00:00');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="checked_at"`
Expected: FAIL — `checked_at` column does not exist / property null.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_30_000001_add_checked_at_to_car_listings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_listings', function (Blueprint $table): void {
            $table->timestamp('checked_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('car_listings', function (Blueprint $table): void {
            $table->dropIndex(['checked_at']);
            $table->dropColumn('checked_at');
        });
    }
};
```

- [ ] **Step 4: Add `checked_at` cast to the model**

In `app/Models/CarListing.php`, add to the `casts()` array (after `'published_at' => 'datetime',`):

```php
'checked_at' => 'datetime',
```

- [ ] **Step 5: Stamp `checked_at` in the scrape upsert**

In `app/Jobs/ScrapeListingJob.php`, inside `persistRecords()`, add `'checked_at' => now(),` to the `CarListing::upsert([...])` array (e.g. directly after the `'published_at' => $this->latestUpdate($sourceDates),` line):

```php
                'published_at' => $this->latestUpdate($sourceDates),
                'checked_at' => now(),
            ], 'fingerprint');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter="checked_at"`
Expected: PASS (both new tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_06_30_000001_add_checked_at_to_car_listings_table.php app/Models/CarListing.php app/Jobs/ScrapeListingJob.php tests/Feature/Jobs/ScrapeListingJobTest.php
git commit -m "feat: add checked_at and stamp it on scrape"
```

---

### Task 2: Scraper removal-probe split (poolable request + interpretation)

Splits removal detection into a poolable request builder and a response interpreter, so the sweep can probe many URLs concurrently. The existing `isListingRemoved` methods stay untouched (and so do their tests).

**Files:**
- Modify: `app/Services/Scrapers/ScraperInterface.php`
- Modify: `app/Services/Scrapers/Scraper.php` (add default impls — mobile.bg behavior)
- Modify: `app/Services/Scrapers/AutoBgScraper.php` (override)
- Modify: `app/Services/Scrapers/CarsBgScraper.php` (override)
- Modify: `app/Services/Scrapers/Car24Scraper.php` (override + extract id/title helper)
- Test: `tests/Feature/Scrapers/RemovalProbeTest.php` (create)

**Interfaces:**
- Produces (on every scraper):
  - `poolRemovalProbe(\Illuminate\Http\Client\PendingRequest $request, string $url): \Illuminate\Http\Client\PendingRequest` — configures and issues the removal GET on the given (possibly pool-bound) request.
  - `isRemovedFromResponse(\Illuminate\Http\Client\Response $response, string $url): bool` — interprets a successful (non-transient) response as removed/alive.
- Consumes: nothing new.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Scrapers/RemovalProbeTest.php`:

```php
<?php

use App\Services\Scrapers\AutoBgScraper;
use App\Services\Scrapers\Car24Scraper;
use App\Services\Scrapers\CarsBgScraper;
use App\Services\Scrapers\MobileBgScraper;
use Illuminate\Support\Facades\Http;

/** Run the poolable probe + interpreter pair sequentially, mirroring the sweep's path. */
function probeRemoved(object $scraper, string $url): bool
{
    $response = $scraper->poolRemovalProbe(Http::withOptions([]), $url);

    return $scraper->isRemovedFromResponse($response, $url);
}

it('mobile.bg probe: 404 is removed, 200 is alive', function (): void {
    Http::fake(['*mobile.bg*' => Http::response('', 404)]);
    expect(probeRemoved(new MobileBgScraper, 'https://www.mobile.bg/obiava-123-x'))->toBeTrue();

    Http::fake(['*mobile.bg*' => Http::response('<html>live</html>', 200)]);
    expect(probeRemoved(new MobileBgScraper, 'https://www.mobile.bg/obiava-123-x'))->toBeFalse();
});

it('auto.bg probe: category redirect or 404 is removed, 200 is alive', function (): void {
    Http::fake(['www.auto.bg/*' => Http::response('', 301, ['Location' => 'https://www.auto.bg/obiavi/jeep/compass'])]);
    expect(probeRemoved(new AutoBgScraper, 'https://www.auto.bg/obiava/123/jeep'))->toBeTrue();

    Http::fake(['www.auto.bg/*' => Http::response('<html>live</html>', 200)]);
    expect(probeRemoved(new AutoBgScraper, 'https://www.auto.bg/obiava/123/jeep'))->toBeFalse();
});

it('cars.bg probe: status_page redirect is removed, 200 is alive', function (): void {
    Http::fake(['*cars.bg*' => Http::response('', 302, ['Location' => 'https://www.cars.bg/status_page.php?m=expired_job_err'])]);
    expect(probeRemoved(new CarsBgScraper, 'https://www.cars.bg/offer/abc123'))->toBeTrue();

    Http::fake(['*cars.bg*' => Http::response('<html>live</html>', 200)]);
    expect(probeRemoved(new CarsBgScraper, 'https://www.cars.bg/offer/abc123'))->toBeFalse();
});

it('car24 probe: API advert null or 404 is removed, advert present is alive', function (): void {
    Http::fake(['api.car24.bg/*' => Http::response(['data' => ['advert' => null]], 200)]);
    expect(probeRemoved(new Car24Scraper, 'https://car24.bg/obiava/75188220/x'))->toBeTrue();

    Http::fake(['api.car24.bg/*' => Http::response('', 404)]);
    expect(probeRemoved(new Car24Scraper, 'https://car24.bg/obiava/75188220/x'))->toBeTrue();

    Http::fake(['api.car24.bg/*' => Http::response(['data' => ['advert' => ['id' => 1]]], 200)]);
    expect(probeRemoved(new Car24Scraper, 'https://car24.bg/obiava/75188220/x'))->toBeFalse();
});

it('car24 probe queries the mobile API with ida and title', function (): void {
    Http::fake(['api.car24.bg/*' => Http::response(['data' => ['advert' => ['id' => 1]]], 200)]);

    probeRemoved(new Car24Scraper, 'https://car24.bg/obiava/75188220/chevrolet-captiva');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.car24.bg/mobile_api/adverts/loadbyid')
        && str_contains($request->url(), 'ida=75188220')
        && str_contains($request->url(), 'title=chevrolet-captiva'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="RemovalProbe"`
Expected: FAIL — `poolRemovalProbe()` / `isRemovedFromResponse()` not defined.

- [ ] **Step 3: Add the methods to the interface**

In `app/Services/Scrapers/ScraperInterface.php`, add (with imports):

```php
<?php

namespace App\Services\Scrapers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

interface ScraperInterface
{
    public function scrape();

    public function isListingRemoved(string $url): bool;

    public function poolRemovalProbe(PendingRequest $request, string $url): PendingRequest;

    public function isRemovedFromResponse(Response $response, string $url): bool;
}
```

- [ ] **Step 4: Add default implementations to the base `Scraper` (mobile.bg behavior)**

In `app/Services/Scrapers/Scraper.php`, add these imports at the top:

```php
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
```

Add these methods (e.g. right after `isListingRemoved`):

```php
/**
 * Issue the removal probe on the given (possibly pool-bound) request.
 * Default mirrors mobile.bg: a plain GET with browser headers.
 */
public function poolRemovalProbe(PendingRequest $request, string $url): PendingRequest
{
    return $request->withHeaders($this->browserHeaders())->get($url);
}

/**
 * Interpret a non-transient response. Default treats a 404 as removed.
 */
public function isRemovedFromResponse(Response $response, string $url): bool
{
    return $response->notFound();
}
```

- [ ] **Step 5: Override in `AutoBgScraper`**

In `app/Services/Scrapers/AutoBgScraper.php`, add imports `use Illuminate\Http\Client\PendingRequest;` and `use Illuminate\Http\Client\Response;`, then add:

```php
public function poolRemovalProbe(PendingRequest $request, string $url): PendingRequest
{
    return $request->withoutRedirecting()->withHeaders($this->browserHeaders())->get($url);
}

public function isRemovedFromResponse(Response $response, string $url): bool
{
    if ($response->notFound()) {
        return true;
    }

    return $response->redirect()
        && str_contains((string) $response->header('Location'), '/obiavi/');
}
```

- [ ] **Step 6: Override in `CarsBgScraper`**

In `app/Services/Scrapers/CarsBgScraper.php`, add the same two imports, then add:

```php
public function poolRemovalProbe(PendingRequest $request, string $url): PendingRequest
{
    return $request->withoutRedirecting()->withHeaders($this->browserHeaders())->get($url);
}

public function isRemovedFromResponse(Response $response, string $url): bool
{
    return $response->redirect()
        && str_contains((string) $response->header('Location'), 'status_page.php');
}
```

- [ ] **Step 7: Override in `Car24Scraper` (+ extract id/title helper)**

In `app/Services/Scrapers/Car24Scraper.php`, add imports `use Illuminate\Http\Client\PendingRequest;` and `use Illuminate\Http\Client\Response;`. Add a private helper and the two overrides:

```php
/**
 * Extract the (ida, title) pair the mobile API needs from an /obiava/ URL.
 *
 * @return array{0: ?string, 1: string}
 */
private function advertQuery(string $url): array
{
    preg_match('/[\\\\\/]obiava[\\\\\/](\d+)(?=[\\\\\/]|$)/', $url, $matches);

    return [$matches[1] ?? null, Str::afterLast($url, '/')];
}

public function poolRemovalProbe(PendingRequest $request, string $url): PendingRequest
{
    [$id, $title] = $this->advertQuery($url);

    return $request->acceptJson()
        ->withQueryParameters(['ida' => $id, 'title' => $title])
        ->get($this->url_single_listing);
}

public function isRemovedFromResponse(Response $response, string $url): bool
{
    if ($response->status() === 404) {
        return true;
    }

    if (! $response->successful()) {
        return false;
    }

    return is_null($response->json('data.advert'));
}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --compact --filter="RemovalProbe"`
Expected: PASS (all probe tests).

- [ ] **Step 9: Run the existing scraper suite to confirm no regression**

Run: `php artisan test --compact tests/Feature/Scrapers`
Expected: PASS (existing `isListingRemoved` tests still green).

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Scrapers/ScraperInterface.php app/Services/Scrapers/Scraper.php app/Services/Scrapers/AutoBgScraper.php app/Services/Scrapers/CarsBgScraper.php app/Services/Scrapers/Car24Scraper.php tests/Feature/Scrapers/RemovalProbeTest.php
git commit -m "feat: split scraper removal detection into poolable probe + interpreter"
```

---

### Task 3: Rolling sweep command + config + hourly schedule

Rewrites `listings:clean-up-removed` into a bounded, concurrent, rolling sweep; adds its config; switches the schedule from weekly to hourly.

**Files:**
- Create: `config/listings.php`
- Modify: `app/Console/Commands/CleanUpRemovedListingsCommand.php` (full rewrite of `handle`)
- Modify: `routes/console.php` (schedule)
- Test: `tests/Feature/Commands/CleanUpRemovedListingsCommandTest.php` (create)

**Interfaces:**
- Consumes: `car_listings.checked_at` (Task 1); `poolRemovalProbe()` / `isRemovedFromResponse()` (Task 2).
- Produces: command `listings:clean-up-removed` with option `--limit=`; config keys `listings.cleanup.batch_limit`, `listings.cleanup.pool_concurrency`.

- [ ] **Step 1: Create the config file**

Create `config/listings.php`:

```php
<?php

return [
    'cleanup' => [
        // Listings probed per run (oldest checked_at first).
        'batch_limit' => 5000,

        // Max concurrent HTTP probes in flight per Http::pool chunk.
        'pool_concurrency' => 25,
    ],
];
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/Commands/CleanUpRemovedListingsCommandTest.php`:

```php
<?php

use App\Models\CarListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function listing(array $sourceUrls, ?string $checkedAt): CarListing
{
    return CarListing::factory()->create([
        'source_urls' => $sourceUrls,
        'checked_at' => $checkedAt,
    ]);
}

it('deletes a listing removed from all its sources', function (): void {
    $l = listing(['https://www.mobile.bg/obiava-123-x'], null);

    Http::fake(['*mobile.bg*' => Http::response('', 404)]);

    $this->artisan('listings:clean-up-removed')->assertSuccessful();

    expect(CarListing::find($l->id))->toBeNull();
});

it('prunes only the dead source from a partially-removed multi-source listing', function (): void {
    $l = listing([
        'https://www.mobile.bg/obiava-123-x',
        'https://www.auto.bg/obiava/456/x',
    ], null);

    Http::fake([
        '*mobile.bg*' => Http::response('<html>live</html>', 200),
        'www.auto.bg/*' => Http::response('', 301, ['Location' => 'https://www.auto.bg/obiavi/bmw']),
    ]);

    $this->artisan('listings:clean-up-removed')->assertSuccessful();

    $l->refresh();
    expect($l->source_urls)->toBe(['https://www.mobile.bg/obiava-123-x'])
        ->and($l->checked_at)->not->toBeNull();
});

it('bumps checked_at for an all-alive listing without deleting', function (): void {
    $l = listing(['https://www.mobile.bg/obiava-123-x'], null);

    Http::fake(['*mobile.bg*' => Http::response('<html>live</html>', 200)]);

    $this->artisan('listings:clean-up-removed')->assertSuccessful();

    expect(CarListing::find($l->id))->not->toBeNull()
        ->and($l->fresh()->checked_at)->not->toBeNull();
});

it('skips a listing when a source is transient (5xx) — no delete, checked_at untouched', function (): void {
    $l = listing(['https://www.mobile.bg/obiava-123-x'], null);

    Http::fake(['*mobile.bg*' => Http::response('', 503)]);

    $this->artisan('listings:clean-up-removed')->assertSuccessful();

    expect(CarListing::find($l->id))->not->toBeNull()
        ->and($l->fresh()->checked_at)->toBeNull();
});

it('respects --limit and probes the oldest checked_at first', function (): void {
    $old = listing(['https://www.mobile.bg/obiava-OLD-x'], null);                       // null = oldest
    $fresh = listing(['https://www.mobile.bg/obiava-FRESH-x'], now()->toDateTimeString());

    Http::fake(['*mobile.bg*' => Http::response('', 404)]);

    $this->artisan('listings:clean-up-removed', ['--limit' => 1])->assertSuccessful();

    // Only the oldest (null checked_at) was in the batch and got deleted.
    expect(CarListing::find($old->id))->toBeNull()
        ->and(CarListing::find($fresh->id))->not->toBeNull();
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact --filter="CleanUpRemovedListings"`
Expected: FAIL — current command has no `--limit`, does not use `checked_at`, and serial logic differs.

- [ ] **Step 4: Rewrite the command**

Replace the full body of `app/Console/Commands/CleanUpRemovedListingsCommand.php`:

```php
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CleanUpRemovedListingsCommand extends Command
{
    protected $signature = 'listings:clean-up-removed {--limit=}';

    protected $description = 'Probe the least-recently-verified listings and remove those gone from all sources';

    public function handle(): void
    {
        $limit = (int) ($this->option('limit') ?: config('listings.cleanup.batch_limit'));
        $concurrency = max(1, (int) config('listings.cleanup.pool_concurrency'));

        Log::channel(LogChannels::LISTINGS)->info("Listings clean up started (limit $limit).");

        $listings = CarListing::query()
            ->orderByRaw('checked_at IS NULL DESC')
            ->orderBy('checked_at')
            ->limit($limit)
            ->get();

        $probes = $this->buildProbes($listings);
        $classifications = $this->classifyAll($probes, $concurrency);

        foreach ($listings as $listing) {
            $this->resolveListing($listing, $classifications[$listing->id] ?? []);
        }

        Log::channel(LogChannels::LISTINGS)->info('Listings clean up completed.');
    }

    /**
     * Flatten listings into per-URL probes, interleaved by source so each pool
     * chunk is spread across hosts. A URL with no known scraper is recorded as
     * 'alive' immediately (kept, never blocks on a probe).
     *
     * @return list<array{listing_id:int,url:string,scraper:Scraper}>
     */
    private function buildProbes($listings): array
    {
        $byHost = [];

        foreach ($listings as $listing) {
            foreach ($listing->source_urls as $url) {
                $scraper = $this->resolveScraper($url);

                if (! $scraper) {
                    continue;
                }

                $byHost[$scraper::class][] = ['listing_id' => $listing->id, 'url' => $url, 'scraper' => $scraper];
            }
        }

        return $this->interleave($byHost);
    }

    /**
     * Round-robin the per-host queues so a single host is never hammered.
     *
     * @param  array<string, list<array{listing_id:int,url:string,scraper:Scraper}>>  $byHost
     * @return list<array{listing_id:int,url:string,scraper:Scraper}>
     */
    private function interleave(array $byHost): array
    {
        $queues = array_values($byHost);
        $interleaved = [];
        $remaining = true;

        while ($remaining) {
            $remaining = false;

            foreach ($queues as &$queue) {
                if ($queue !== []) {
                    $interleaved[] = array_shift($queue);
                    $remaining = true;
                }
            }
            unset($queue);
        }

        return $interleaved;
    }

    /**
     * Probe every URL in concurrency-capped pool chunks and classify each as
     * 'removed' | 'alive' | 'unknown', grouped by listing id and keyed by url.
     *
     * @param  list<array{listing_id:int,url:string,scraper:Scraper}>  $probes
     * @return array<int, array<string, string>>
     */
    private function classifyAll(array $probes, int $concurrency): array
    {
        $result = [];

        foreach (array_chunk($probes, $concurrency) as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk): void {
                foreach ($chunk as $i => $probe) {
                    $probe['scraper']->poolRemovalProbe($pool->as((string) $i), $probe['url']);
                }
            });

            foreach ($chunk as $i => $probe) {
                $result[$probe['listing_id']][$probe['url']] = $this->classify(
                    $responses[(string) $i] ?? $responses[$i] ?? null,
                    $probe['scraper'],
                    $probe['url'],
                );
            }
        }

        return $result;
    }

    /**
     * Transient failures (connection error, 5xx, 429) are 'unknown' and never
     * treated as removed; otherwise the scraper interprets the response.
     */
    private function classify(mixed $response, Scraper $scraper, string $url): string
    {
        if (! $response instanceof Response) {
            return 'unknown';
        }

        if ($response->serverError() || $response->status() === 429) {
            return 'unknown';
        }

        return $scraper->isRemovedFromResponse($response, $url) ? 'removed' : 'alive';
    }

    /**
     * Apply the per-listing decision: skip on any unknown, delete when all
     * removed, prune when partial, bump checked_at when alive.
     *
     * @param  array<string, string>  $classByUrl
     */
    private function resolveListing(CarListing $listing, array $classByUrl): void
    {
        if (in_array('unknown', $classByUrl, true)) {
            Log::channel(LogChannels::LISTINGS)->info("Listing $listing->fingerprint skipped (transient).");

            return;
        }

        $liveUrls = [];

        foreach ($listing->source_urls as $url) {
            if (($classByUrl[$url] ?? 'alive') !== 'removed') {
                $liveUrls[] = $url;
            }
        }

        if ($liveUrls === []) {
            $listing->delete();
            Log::channel(LogChannels::LISTINGS)->info("Listing $listing->fingerprint removed.");

            return;
        }

        $update = ['checked_at' => now()];

        if (count($liveUrls) !== count($listing->source_urls)) {
            $update['source_urls'] = $liveUrls;
            Log::channel(LogChannels::LISTINGS)->info("Listing $listing->fingerprint pruned to ".count($liveUrls).' live source(s).');
        }

        $listing->update($update);
    }

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
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter="CleanUpRemovedListings"`
Expected: PASS (all five command tests).

- [ ] **Step 6: Switch the schedule to hourly**

In `routes/console.php`, replace the cleanup schedule line:

```php
Schedule::command(CleanUpRemovedListingsCommand::class)->weeklyOn(2, 0);
```

with:

```php
Schedule::command(CleanUpRemovedListingsCommand::class)->hourly()->withoutOverlapping();
```

- [ ] **Step 7: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS (no regressions).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/listings.php app/Console/Commands/CleanUpRemovedListingsCommand.php routes/console.php tests/Feature/Commands/CleanUpRemovedListingsCommandTest.php
git commit -m "feat: rewrite listing cleanup as bounded rolling concurrent sweep"
```

---

## Self-Review

**Spec coverage:**
- §1 schema `checked_at` + index → Task 1 (migration, cast).
- §2 scrape stamping → Task 1 (upsert).
- §3 rolling sweep (oldest-first select, concurrent pool, classify, per-listing decision, hard delete, prune, skip-on-transient) → Task 3 (command rewrite + tests).
- §4 per-source probe refactor (request/interpret split, classification rule) → Task 2 + `classify()` in Task 3.
- §5 schedule hourly + config + round-robin interleave → Task 3 (config, schedule, `interleave()`).
- Hard-delete semantics, transient-never-removed, all-sources-confirm rule → Global Constraints + Task 3 tests.

**Placeholder scan:** none — every code step has complete code; every run step has an exact command and expected outcome.

**Type consistency:** `poolRemovalProbe(PendingRequest, string): PendingRequest` and `isRemovedFromResponse(Response, string): bool` are identical across the interface (Task 2 Step 3), base/overrides (Steps 4-7), and the command's `classifyAll`/`classify` (Task 3 Step 4). `checked_at` is referenced consistently as a `datetime` cast. `source_urls` is treated as a numeric list throughout (build/prune/test).