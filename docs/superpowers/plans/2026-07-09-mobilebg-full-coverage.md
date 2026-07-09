# mobile.bg Full-Catalog Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Break mobile.bg's ~3,000-listing per-query cap by scraping the catalog segmented per make (descending to model for large makes), so mobile.bg reaches parity with the other sources for both an initial backfill and an ongoing weekly refresh.

**Architecture:** A new `MobileBgScraper::scrapeSegment($slug, $page)` fetches the slug URL `/obiavi/avtomobili-dzhipove/{slug}[/p-N]` and parses the same `.ads2023 .item` cards the existing scraper already reads. `fetchMakeModelSlugs()` reads mobile.bg's browse-sitemap for the authoritative make→model slug map. A `ScrapeMobileBgCatalogCommand` dispatches one `SweepMobileBgSegmentJob` per make; each job pages its segment inline until the server cap and, if it reaches the cap (meaning there's more than one segment's worth), dispatches child jobs for that make's models. Persistence is shared via an extracted `ListingPersister` service.

**Tech Stack:** PHP 8.3, Laravel 12, Symfony DomCrawler, Pest 4, PostgreSQL, queued jobs.

## Global Constraints

- PHP: explicit return types and param type hints on every method; curly braces on all control structures; constructor property promotion.
- No `env()` outside `config/`. Read tunables via `config('listings.mobilebg_sweep.*')`.
- Prefer `Model::query()` over `DB::`. `source_urls`/`source_dates` are Postgres `json` columns — never `LIKE`/cast them in SQL.
- Run `vendor/bin/pint --dirty --format agent` before each commit.
- Tests are Pest feature tests; fake all HTTP with `Http::fake` and use `RefreshDatabase` when touching the DB.
- Scope is cars only (`avtomobili-dzhipove`). Do not touch other sources' scrapers or the cleanup command.
- Source tag for all scraped mobile.bg records is the string `mobile.bg`.

---

### Task 1: Extract `ListingPersister` service

Move the source-agnostic persistence logic out of `ScrapeListingJob` into a service so the new sweep job can reuse it. `ScrapeListingJob::persistRecords()` stays as a thin delegator to preserve its callers (including existing tests).

**Files:**
- Create: `app/Services/ListingPersister.php`
- Modify: `app/Jobs/ScrapeListingJob.php` (remove `persistRecords` body + `latestUpdate` + `toDateTimeString`; delegate)
- Test: `tests/Feature/Services/ListingPersisterTest.php`

**Interfaces:**
- Produces: `App\Services\ListingPersister::persist(array $records, \App\Services\Scrapers\Scraper $scraper): void`
- Existing `ScrapeListingJob::persistRecords(array $results, Scraper $scraper): void` remains callable (now delegates).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\CarListing;
use App\Services\ListingPersister;
use App\Services\Scrapers\MobileBgScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists a scraped record into a car listing', function (): void {
    $record = [
        'title' => 'Audi A4 AVANT',
        'price' => '7295 EUR',
        'link' => 'www.mobile.bg/obiava-11572426012950088-audi-a4',
        'description' => 'Audi A4',
        'image' => null,
        'location' => 'София',
        'source' => 'mobile.bg',
        'published_at' => Carbon::parse('2019-10-30 09:00:12'),
        'params' => ['production_year' => 2012, 'mileage' => 201734, 'fuel' => 'Diesel', 'transmission' => 'Automatic'],
    ];

    app(ListingPersister::class)->persist([$record], new MobileBgScraper);

    $listing = CarListing::sole();
    expect($listing->title)->toBe('Audi A4 AVANT')
        ->and($listing->source_dates)->toBe(['mobile.bg' => ['created' => '2019-10-30 09:00:12']]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="persists a scraped record"`
Expected: FAIL — `Class "App\Services\ListingPersister" not found`.

- [ ] **Step 3: Create the service**

Create `app/Services/ListingPersister.php` by moving the exact logic currently in `ScrapeListingJob::persistRecords`, `latestUpdate`, and `toDateTimeString`:

```php
<?php

namespace App\Services;

use App\Models\CarListing;
use App\Services\Scrapers\Scraper;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class ListingPersister
{
    public function persist(array $records, Scraper $scraper): void
    {
        foreach ($records as $record) {
            $mileage = Arr::get($record, 'params.mileage');
            $year = Arr::get($record, 'params.production_year');
            $fuel_type = Arr::get($record, 'params.fuel');
            $source_url = $scraper->formatListingUrl(Arr::get($record, 'link'));
            $price = $record['source'] !== 'car24.bg'
                ? $scraper->extractPrice(Arr::get($record, 'price'))['eur']
                : $record['price'];

            $image_url = Arr::get($record, 'image');

            $uuid = Deduplication::make($image_url, [
                'mileage' => $mileage, 'year' => $year, 'fuel_type' => $fuel_type, 'price' => $price,
            ]);

            $listing = CarListing::where('fingerprint', $uuid)->first();
            $sources = $listing ? $listing->source_urls : [];
            $sourceDates = $listing ? ($listing->source_dates ?? []) : [];

            if (! in_array($source_url, $sources)) {
                $sources[] = $source_url;
            }

            $entry = array_filter([
                'created' => $this->toDateTimeString(Arr::get($record, 'published_at')),
                'updated' => $this->toDateTimeString(Arr::get($record, 'updated_at')),
            ]);

            if ($entry !== []) {
                $sourceDates[$record['source']] = $entry;
            }

            $resolved = app(ListingMakeModelResolver::class)->resolve(Arr::get($record, 'title'));

            CarListing::upsert([
                'fingerprint' => $uuid,
                'title' => Arr::get($record, 'title'),
                'car_make_id' => $resolved['make']?->id,
                'car_model_id' => $resolved['model']?->id,
                'price' => $price ?? 0,
                'description' => Arr::get($record, 'description'),
                'year' => $year,
                'fuel_type' => $fuel_type,
                'mileage' => $mileage,
                'location' => Arr::get($record, 'location'),
                'transmission' => Arr::get($record, 'params.transmission'),
                'image_url' => $image_url,
                'source_urls' => json_encode($sources),
                'source_dates' => json_encode($sourceDates),
                'published_at' => $this->latestUpdate($sourceDates),
                'checked_at' => now(),
            ], 'fingerprint');
        }

        if ($records !== []) {
            SitemapCache::flush();
        }
    }

    private function latestUpdate(array $sourceDates): ?string
    {
        $updates = collect($sourceDates)
            ->map(fn ($entry) => is_array($entry) ? ($entry['updated'] ?? $entry['created'] ?? null) : $entry)
            ->filter();

        return $updates->isEmpty() ? null : $updates->max();
    }

    private function toDateTimeString(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return $value instanceof Carbon ? $value->toDateTimeString() : (string) $value;
    }
}
```

- [ ] **Step 4: Delegate from `ScrapeListingJob`**

In `app/Jobs/ScrapeListingJob.php`: delete the private `latestUpdate` and `toDateTimeString` methods and replace the `persistRecords` body with a delegation. Keep the method signature (callers/tests rely on it). Remove now-unused imports (`Deduplication`, `ListingMakeModelResolver`, `SitemapCache`, `Carbon`, `CarListing`) only if no longer referenced elsewhere in the file — verify before removing.

```php
    public function persistRecords(array $results, Scraper $scraper): void
    {
        app(\App\Services\ListingPersister::class)->persist($results, $scraper);
    }
```

- [ ] **Step 5: Run the new test and the existing job test**

Run: `php artisan test --compact tests/Feature/Services/ListingPersisterTest.php tests/Feature/Jobs/ScrapeListingJobTest.php`
Expected: PASS (all). The existing `ScrapeListingJobTest` proves the delegation preserves behavior.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/ListingPersister.php app/Jobs/ScrapeListingJob.php tests/Feature/Services/ListingPersisterTest.php
git commit -m "refactor: extract ListingPersister from ScrapeListingJob"
```

---

### Task 2: `MobileBgScraper::scrapeSegment()` + `parseCards()` + config block

Add slug-based scraping that reuses the existing card parsing, and introduce the `mobilebg_sweep` config block.

**Files:**
- Modify: `app/Services/Scrapers/MobileBgScraper.php`
- Modify: `config/listings.php`
- Test: `tests/Feature/Scrapers/MobileBgScraperTest.php` (append)

**Interfaces:**
- Produces: `MobileBgScraper::scrapeSegment(string $slug, int $page = 1): array` — returns the same record shape as `scrape()` (`title, price, link, description, image, location, source, published_at, params`).
- Produces (private): `MobileBgScraper::parseCards(\Symfony\Component\DomCrawler\Crawler $crawler, string $context): array`.
- Consumes: `config('listings.mobilebg_sweep.connect_timeout')`, `config('listings.mobilebg_sweep.request_timeout')`.

- [ ] **Step 1: Add the config block**

In `config/listings.php`, add a `mobilebg_sweep` key inside the returned array (sibling of `cleanup` and `autobg_sweep`):

```php
    'mobilebg_sweep' => [
        // mobile.bg caps any result set at ~151 pages. Treated as the truncation
        // signal: a segment returning cards up to this page is assumed to have
        // more and is split by model.
        'page_cap' => 150,

        // Pause between page requests (ms) to bound the sustained request rate.
        'pause_ms' => 300,

        // Per-request TCP connect and total timeouts (seconds).
        'connect_timeout' => 10,
        'request_timeout' => 20,
    ],
```

- [ ] **Step 2: Write the failing tests**

Append to `tests/Feature/Scrapers/MobileBgScraperTest.php`:

```php
it('scrapes a make/model segment via the slug URL and parses windows-1251 cards', function (): void {
    $html = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=windows-1251"></head><body>'
        .'<div class="ads2023"><div class="item">'
        .'<div class="zaglavie"><a href="/obiava-11779353449257335-bmw-320">x</a></div>'
        .'<div class="title">БМВ 320</div>'
        .'<div class="price">1 000 EUR</div>'
        .'<div class="info">Дизел, автоматик</div>'
        .'<div class="location">гр. София</div>'
        .'<div class="params">2012 г. 200 000 км Дизел</div>'
        .'</div></div></body></html>';
    $cp1251 = mb_convert_encoding($html, 'Windows-1251', 'UTF-8');
    Http::fake(['www.mobile.bg/*' => Http::response($cp1251)]);

    $results = (new MobileBgScraper)->scrapeSegment('bmw/320', 1);

    expect($results)->toHaveCount(1)
        ->and($results[0]['title'])->toBe('БМВ 320')
        ->and($results[0]['location'])->toBe('гр. София')
        ->and($results[0]['source'])->toBe('mobile.bg')
        ->and($results[0]['link'])->toContain('obiava-11779353449257335');
});

it('builds the /p-N URL for pages beyond the first', function (): void {
    Http::fake(['www.mobile.bg/*' => Http::response('<html></html>')]);

    (new MobileBgScraper)->scrapeSegment('bmw', 3);

    Http::assertSent(fn ($request) => $request->url() === 'https://www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/p-3');
});

it('omits the /p-1 suffix on the first page of a segment', function (): void {
    Http::fake(['www.mobile.bg/*' => Http::response('<html></html>')]);

    (new MobileBgScraper)->scrapeSegment('bmw', 1);

    Http::assertSent(fn ($request) => $request->url() === 'https://www.mobile.bg/obiavi/avtomobili-dzhipove/bmw');
});

it('returns an empty array when a segment page is not successful', function (): void {
    Http::fake(['www.mobile.bg/*' => Http::response('', 404)]);

    expect((new MobileBgScraper)->scrapeSegment('bmw', 5))->toBe([]);
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact --filter="segment"`
Expected: FAIL — `Method ...scrapeSegment does not exist` / `Call to undefined method`.

- [ ] **Step 4: Implement `parseCards` + `scrapeSegment`, refactor `scrape`**

In `app/Services/Scrapers/MobileBgScraper.php`, refactor `scrape()` to delegate card parsing to a new private `parseCards()`, and add `scrapeSegment()`. Replace the current `scrape()` body's parsing loop:

```php
    public function scrape(int $page = 1): array
    {
        $response = Http::withHeaders([
            ...$this->browserHeaders(),
            'Referer' => 'https://www.mobile.bg/',
        ])->get("https://www.mobile.bg/pcgi/mobile.cgi?act=3&sink=1&f1=$page");

        if (! $response->successful()) {
            return [];
        }

        return $this->parseCards(new Crawler($response->body()), "page $page");
    }

    /**
     * Scrape one page of a make (or make/model) segment via the slug URL.
     * Page 1 has no suffix; later pages use the `/p-N` form. Reuses the shared
     * `.ads2023 .item` card parser. Pages are served as windows-1251.
     *
     * @return array<int, array{title: string, price: string, link: string|null, description: string, image: string|null, location: string, source: string, published_at: \Carbon\Carbon|null, params: array<string, mixed>}>
     *
     * @throws ConnectionException
     */
    public function scrapeSegment(string $slug, int $page = 1): array
    {
        $suffix = $page > 1 ? "/p-$page" : '';

        $response = Http::withHeaders([
            ...$this->browserHeaders(),
            'Referer' => 'https://www.mobile.bg/',
        ])
            ->connectTimeout((int) config('listings.mobilebg_sweep.connect_timeout'))
            ->timeout((int) config('listings.mobilebg_sweep.request_timeout'))
            ->get("https://www.mobile.bg/obiavi/avtomobili-dzhipove/{$slug}{$suffix}");

        if (! $response->successful()) {
            return [];
        }

        $crawler = new Crawler;
        $crawler->addHtmlContent($response->body(), 'windows-1251');

        return $this->parseCards($crawler, "segment $slug p$page");
    }

    /**
     * Parse `.ads2023 .item` result cards into the shared scraped-listing shape.
     * `$context` labels failures in the log (page number or segment slug).
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseCards(Crawler $crawler, string $context): array
    {
        $results = [];

        $crawler->filter('.ads2023 .item')->each(function (Crawler $node) use (&$results, $context): void {
            try {
                $link = $node->filter('.zaglavie>a')->count() > 0
                    ? ltrim(trim($node->filter('.zaglavie>a')->attr('href')), '/')
                    : null;

                $results[] = [
                    'title' => trim($node->filter('.title')->text('')),
                    'price' => trim($node->filter('.price')->text('')),
                    'link' => $link,
                    'description' => \Str::excerpt(trim($node->filter('.info')->text('')), options: ['radius' => 500]),
                    'image' => $node->filter('.photo .big a.image .pic')->count() > 0 ? ltrim($node->filter('.photo .big a.image .pic')->attr('src'), '/') : null,
                    'location' => trim($node->filter('.location')->text('')),
                    'source' => 'mobile.bg',
                    'published_at' => $this->getPublishedDate($link),
                    'params' => $this->extractListingParams($node->filter('.params')->first()->text()),
                ];
            } catch (\Exception $e) {
                Log::channel(LogChannels::SCRAPING_MOBILE)->error("Failed to scrape mobile.bg ads for $context - {$e->getMessage()}");
            }
        });

        return $results;
    }
```

Note: `.location` `->text('')` gains a default so a missing node no longer throws (previously `->text()`), matching the other optional fields.

- [ ] **Step 5: Run the segment tests and the full scraper test file**

Run: `php artisan test --compact tests/Feature/Scrapers/MobileBgScraperTest.php`
Expected: PASS — new segment tests plus the pre-existing `scrape(1)` / published-date / removal tests all green (proves the `parseCards` refactor didn't regress the flat scraper).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Scrapers/MobileBgScraper.php config/listings.php tests/Feature/Scrapers/MobileBgScraperTest.php
git commit -m "feat: add mobile.bg slug-segment scraping (scrapeSegment + parseCards)"
```

---

### Task 3: `MobileBgScraper::fetchMakeModelSlugs()`

Read mobile.bg's browse-sitemap into a `make => [model slugs]` map (mobile.bg's authoritative slugs).

**Files:**
- Modify: `app/Services/Scrapers/MobileBgScraper.php`
- Test: `tests/Feature/Scrapers/MobileBgScraperTest.php` (append)

**Interfaces:**
- Produces: `MobileBgScraper::fetchMakeModelSlugs(): array<string, list<string>>` — keys are make slugs, values are that make's model slugs (may be empty).

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Scrapers/MobileBgScraperTest.php`:

```php
it('parses the browse-sitemap into a make to models slug map', function (): void {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset>'
        .'<url><loc>https://www.mobile.bg/obiavi/avtomobili-dzhipove/ac</loc></url>'
        .'<url><loc>https://www.mobile.bg/obiavi/avtomobili-dzhipove/ac/drugi</loc></url>'
        .'<url><loc>https://www.mobile.bg/obiavi/avtomobili-dzhipove/bmw</loc></url>'
        .'<url><loc>https://www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/116</loc></url>'
        .'<url><loc>https://www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/x5</loc></url>'
        .'</urlset>';
    Http::fake(['www.mobile.bg/sitemap/*' => Http::response(gzencode($xml))]);

    $map = (new MobileBgScraper)->fetchMakeModelSlugs();

    expect($map)->toBe([
        'ac' => ['drugi'],
        'bmw' => ['116', 'x5'],
    ]);
});

it('returns an empty map when the browse-sitemap request fails', function (): void {
    Http::fake(['www.mobile.bg/sitemap/*' => Http::response('', 500)]);

    expect((new MobileBgScraper)->fetchMakeModelSlugs())->toBe([]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="browse-sitemap"`
Expected: FAIL — `Call to undefined method ...fetchMakeModelSlugs`.

- [ ] **Step 3: Implement `fetchMakeModelSlugs`**

Add to `app/Services/Scrapers/MobileBgScraper.php`:

```php
    /**
     * Download and parse mobile.bg's car browse-sitemap into a make => [model
     * slugs] map, using mobile.bg's own slug conventions (which differ from the
     * shared catalog). Depth-1 loc URLs are makes; depth-2 are models. The file
     * is gzipped; a live server may also transfer-encode it, so decode falls
     * back to the raw body.
     *
     * @return array<string, list<string>>
     *
     * @throws ConnectionException
     */
    public function fetchMakeModelSlugs(): array
    {
        $response = Http::withHeaders($this->browserHeaders())
            ->get('https://www.mobile.bg/sitemap/sitemap-avtomobili-dzhipove-avtomobili-dzhipove.xml.gz');

        if (! $response->successful()) {
            return [];
        }

        $xml = @gzdecode($response->body());

        if ($xml === false) {
            $xml = $response->body();
        }

        $map = [];

        preg_match_all(
            '#/obiavi/avtomobili-dzhipove/([a-z0-9-]+)(?:/([a-z0-9-]+))?</loc>#i',
            $xml,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $make = $match[1];
            $map[$make] ??= [];

            if (isset($match[2]) && $match[2] !== '') {
                $map[$make][] = $match[2];
            }
        }

        return $map;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter="browse-sitemap"`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Scrapers/MobileBgScraper.php tests/Feature/Scrapers/MobileBgScraperTest.php
git commit -m "feat: parse mobile.bg browse-sitemap into make/model slug map"
```

---

### Task 4: `SweepMobileBgSegmentJob`

Page one segment inline until the server cap; persist each page; if the segment reaches the cap and has child model slugs, dispatch a child job per model.

**Files:**
- Create: `app/Jobs/SweepMobileBgSegmentJob.php`
- Test: `tests/Feature/Jobs/SweepMobileBgSegmentJobTest.php`

**Interfaces:**
- Consumes: `MobileBgScraper::scrapeSegment(string, int)`, `ListingPersister::persist(array, Scraper)`, `config('listings.mobilebg_sweep.page_cap'|'pause_ms')`.
- Produces: `new SweepMobileBgSegmentJob(string $slug, array $childSlugs = [])`; dispatched on queue `scrape-listings`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Jobs\SweepMobileBgSegmentJob;
use App\Models\CarListing;
use App\Services\Scrapers\MobileBgScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function segmentCard(string $id): string
{
    return '<div class="item">'
        .'<div class="zaglavie"><a href="/obiava-'.$id.'-bmw-320">x</a></div>'
        .'<div class="title">BMW 320</div><div class="price">1 000 EUR</div>'
        .'<div class="info">Diesel</div><div class="location">Sofia</div>'
        .'<div class="params">2012 г. 200 000 км</div></div>';
}

function segmentPage(string ...$ids): string
{
    return '<html><body><div class="ads2023">'.implode('', array_map('segmentCard', $ids)).'</div></div></body></html>';
}

it('persists every page of a segment and stops at the first empty page', function (): void {
    config()->set('listings.mobilebg_sweep.page_cap', 150);
    config()->set('listings.mobilebg_sweep.pause_ms', 0);
    Bus::fake();

    Http::fake([
        'www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/320' => Http::response(segmentPage('11779353449257335')),
        'www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/320/p-2' => Http::response(segmentPage('11779353449257336')),
        'www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/320/p-3' => Http::response('<html><body><div class="ads2023"></div></body></html>'),
    ]);

    (new SweepMobileBgSegmentJob('bmw/320'))->handle(new MobileBgScraper, app(App\Services\ListingPersister::class));

    expect(CarListing::count())->toBe(2);
    Bus::assertNothingDispatched();
});

it('descends into child model segments when a make reaches the page cap', function (): void {
    config()->set('listings.mobilebg_sweep.page_cap', 2);
    config()->set('listings.mobilebg_sweep.pause_ms', 0);
    Bus::fake();

    // Both pages full up to the (test) cap of 2 -> treated as truncated.
    Http::fake([
        'www.mobile.bg/obiavi/avtomobili-dzhipove/bmw' => Http::response(segmentPage('11779353449257335')),
        'www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/p-2' => Http::response(segmentPage('11779353449257336')),
    ]);

    (new SweepMobileBgSegmentJob('bmw', ['116', 'x5']))->handle(new MobileBgScraper, app(App\Services\ListingPersister::class));

    Bus::assertDispatchedTimes(SweepMobileBgSegmentJob::class, 2);
    Bus::assertDispatched(SweepMobileBgSegmentJob::class, fn ($job) => $job->slug === 'bmw/116');
    Bus::assertDispatched(SweepMobileBgSegmentJob::class, fn ($job) => $job->slug === 'bmw/x5');
});
```

Note: assertions read `$job->slug`, so declare that promoted property `public`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Jobs/SweepMobileBgSegmentJobTest.php`
Expected: FAIL — `Class "App\Jobs\SweepMobileBgSegmentJob" not found`.

- [ ] **Step 3: Implement the job**

Create `app/Jobs/SweepMobileBgSegmentJob.php`:

```php
<?php

namespace App\Jobs;

use App\Misc\LogChannels;
use App\Services\ListingPersister;
use App\Services\Scrapers\MobileBgScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SweepMobileBgSegmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    /**
     * @param  list<string>  $childSlugs  model slugs to descend into if this
     *                                     segment reaches the page cap
     */
    public function __construct(
        public string $slug,
        public array $childSlugs = [],
    ) {}

    public function handle(MobileBgScraper $scraper, ListingPersister $persister): void
    {
        $pageCap = (int) config('listings.mobilebg_sweep.page_cap');
        $pauseMs = (int) config('listings.mobilebg_sweep.pause_ms');
        $reachedCap = false;

        for ($page = 1; $page <= $pageCap; $page++) {
            $records = $scraper->scrapeSegment($this->slug, $page);

            if ($records === []) {
                break;
            }

            $persister->persist($records, $scraper);

            if ($page === $pageCap) {
                $reachedCap = true;
            }

            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        if (! $reachedCap) {
            return;
        }

        if ($this->childSlugs === []) {
            Log::channel(LogChannels::LISTINGS)->warning("mobile.bg segment {$this->slug} reached the page cap with no models to descend into; coverage may be truncated.");

            return;
        }

        foreach ($this->childSlugs as $child) {
            self::dispatch("{$this->slug}/{$child}")->onQueue('scrape-listings');
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel(LogChannels::LISTINGS)->error("mobile.bg segment sweep failed for {$this->slug}: {$exception?->getMessage()}");
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Jobs/SweepMobileBgSegmentJobTest.php`
Expected: PASS (both).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Jobs/SweepMobileBgSegmentJob.php tests/Feature/Jobs/SweepMobileBgSegmentJobTest.php
git commit -m "feat: add SweepMobileBgSegmentJob with cap-based model descent"
```

---

### Task 5: `ScrapeMobileBgCatalogCommand` + schedule

Fetch the slug map and dispatch one segment job per make; register the weekly schedule.

**Files:**
- Create: `app/Console/Commands/ScrapeMobileBgCatalogCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Commands/ScrapeMobileBgCatalogCommandTest.php`

**Interfaces:**
- Consumes: `MobileBgScraper::fetchMakeModelSlugs()`, `SweepMobileBgSegmentJob`.
- Produces: artisan command `scrape:mobilebg-catalog {--make=}`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Jobs\SweepMobileBgSegmentJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function fakeMobileBrowseSitemap(): void
{
    $xml = '<?xml version="1.0"?><urlset>'
        .'<url><loc>https://www.mobile.bg/obiavi/avtomobili-dzhipove/ac</loc></url>'
        .'<url><loc>https://www.mobile.bg/obiavi/avtomobili-dzhipove/ac/drugi</loc></url>'
        .'<url><loc>https://www.mobile.bg/obiavi/avtomobili-dzhipove/bmw</loc></url>'
        .'<url><loc>https://www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/116</loc></url>'
        .'</urlset>';
    Http::fake(['www.mobile.bg/sitemap/*' => Http::response(gzencode($xml))]);
}

it('dispatches one segment job per make with its model slugs', function (): void {
    Bus::fake();
    fakeMobileBrowseSitemap();

    $this->artisan('scrape:mobilebg-catalog')->assertSuccessful();

    Bus::assertDispatchedTimes(SweepMobileBgSegmentJob::class, 2);
    Bus::assertDispatched(SweepMobileBgSegmentJob::class, fn ($job) => $job->slug === 'ac' && $job->childSlugs === ['drugi']);
    Bus::assertDispatched(SweepMobileBgSegmentJob::class, fn ($job) => $job->slug === 'bmw' && $job->childSlugs === ['116']);
});

it('limits the sweep to a single make with --make', function (): void {
    Bus::fake();
    fakeMobileBrowseSitemap();

    $this->artisan('scrape:mobilebg-catalog', ['--make' => 'bmw'])->assertSuccessful();

    Bus::assertDispatchedTimes(SweepMobileBgSegmentJob::class, 1);
    Bus::assertDispatched(SweepMobileBgSegmentJob::class, fn ($job) => $job->slug === 'bmw');
});

it('fails and dispatches nothing when the browse-sitemap is unavailable', function (): void {
    Bus::fake();
    Http::fake(['www.mobile.bg/sitemap/*' => Http::response('', 500)]);

    $this->artisan('scrape:mobilebg-catalog')->assertFailed();

    Bus::assertNothingDispatched();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Commands/ScrapeMobileBgCatalogCommandTest.php`
Expected: FAIL — command `scrape:mobilebg-catalog` not defined.

- [ ] **Step 3: Implement the command**

Create `app/Console/Commands/ScrapeMobileBgCatalogCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\SweepMobileBgSegmentJob;
use App\Misc\LogChannels;
use App\Services\Scrapers\MobileBgScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScrapeMobileBgCatalogCommand extends Command
{
    protected $signature = 'scrape:mobilebg-catalog {--make= : Limit the sweep to a single make slug}';

    protected $description = 'Ingest the full mobile.bg car catalog, segmented per make (descending to model past the page cap)';

    public function handle(MobileBgScraper $scraper): int
    {
        Log::channel(LogChannels::LISTINGS)->info('mobile.bg catalog sweep started.');

        $slugs = $scraper->fetchMakeModelSlugs();

        if ($slugs === []) {
            $this->error('Could not read the mobile.bg browse-sitemap; aborting.');
            Log::channel(LogChannels::LISTINGS)->error('mobile.bg catalog sweep aborted: empty slug map.');

            return self::FAILURE;
        }

        $only = $this->option('make');
        $dispatched = 0;

        foreach ($slugs as $make => $models) {
            if ($only !== null && $make !== $only) {
                continue;
            }

            SweepMobileBgSegmentJob::dispatch($make, array_values($models))->onQueue('scrape-listings');
            $dispatched++;
        }

        $message = "$dispatched mobile.bg make segment job(s) dispatched.";
        $this->info($message);
        Log::channel(LogChannels::LISTINGS)->info("mobile.bg catalog sweep dispatched: $message");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Commands/ScrapeMobileBgCatalogCommandTest.php`
Expected: PASS (all three).

- [ ] **Step 5: Register the weekly schedule**

In `routes/console.php`, add the import and schedule line (Monday 03:30, off-peak, non-overlapping):

```php
use App\Console\Commands\ScrapeMobileBgCatalogCommand;
```

```php
Schedule::command(ScrapeMobileBgCatalogCommand::class)->weeklyOn(1, '03:30')->withoutOverlapping();
```

- [ ] **Step 6: Verify the command and schedule register**

Run: `php artisan schedule:list`
Expected: the `scrape:mobilebg-catalog` entry appears with a weekly Monday 03:30 cadence.

- [ ] **Step 7: Run the full suite, Pint + commit**

Run: `php artisan test --compact`
Expected: PASS (whole suite).

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/ScrapeMobileBgCatalogCommand.php routes/console.php tests/Feature/Commands/ScrapeMobileBgCatalogCommandTest.php
git commit -m "feat: add scrape:mobilebg-catalog command and weekly schedule"
```

---

## Backfill (manual, post-merge)

After deploy, run the one-time backfill and watch the queue drain:

```bash
php artisan scrape:mobilebg-catalog
```

Monitor the `listings` log channel for cap-truncation warnings (a model segment that still hits the cap — the v1 signal that a rare model would need finer splitting). Verify growth with a query on `car_listings` counting rows whose `source_urls` contains `mobile.bg`.

## Notes & deviations from the spec

- **Per-segment job instead of "command dispatches ScrapeListingJob with a `path` per page-chunk."** mobile.bg's page-1 pagination is a sliding window that does not reveal the last page, and the ~151-page server cap can't be seen past — so extent and truncation can only be learned by paging to the cap. A per-segment job that pages inline (each page fetched exactly once) and self-descends on reaching the cap realizes the approved slug-segmentation approach without double-fetching every page. `ScrapeListingJob` is therefore left unchanged except for the `ListingPersister` extraction.
- **`ListingPersister`** was extracted so the new job reuses the exact dedup/persist path without instantiating `ScrapeListingJob`.
- Windows-1251 decoding is handled explicitly in `scrapeSegment` via `Crawler::addHtmlContent(..., 'windows-1251')` and pinned by a test.

## Self-review

- **Spec coverage:** slug scrape (Task 2) ✓; browse-sitemap slug source, no catalog reuse (Task 3) ✓; make→model descent with cap detection + log fallback (Task 4) ✓; command for backfill + weekly schedule, keep existing daily flat scrape untouched (Task 5) ✓; reuse dedup/persist/`published_at`-from-ID (Task 1 + Task 4) ✓; cars-only scope ✓; error handling — sitemap abort (Task 5), non-2xx → `[]` (Task 2), transient retries via job `$tries` (Task 4) ✓; windows-1251 (Task 2) ✓; config block, no `env()` (Task 2) ✓. Cleanup-volume note is monitoring-only (spec non-change) — no task, by design.
- **Placeholder scan:** none — every code and test step contains full code and exact commands.
- **Type consistency:** `scrapeSegment(string,int):array`, `fetchMakeModelSlugs():array`, `ListingPersister::persist(array,Scraper):void`, `SweepMobileBgSegmentJob(public string $slug, public array $childSlugs=[])` used identically across Tasks 2–5; `$job->slug`/`$job->childSlugs` are public as the tests require.