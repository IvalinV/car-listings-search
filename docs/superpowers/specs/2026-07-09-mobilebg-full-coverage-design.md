# mobile.bg full-catalog coverage via slug segmentation

**Date:** 2026-07-09
**Status:** Approved (design) — pending spec review

## Problem

mobile.bg is Bulgaria's largest car marketplace, yet only ~2,151 mobile.bg listings
exist in the DB versus ~72k (auto.bg) and ~107k (car24.bg). Investigation showed this
is **not** a cleanup/removal bug and **not** a cadence bug: the scraper fetches a single
flat search endpoint —

```
https://www.mobile.bg/pcgi/mobile.cgi?act=3&sink=1&f1={page}
```

— which mobile.bg hard-caps server-side at **~151 pages (~3,000 listings)**. No single
query can return more, so the collection is permanently stuck near that ceiling. The
listings are also not date-sorted, so the daily/30-min job doesn't even reliably capture
new arrivals.

There is no JSON API equivalent to auto.bg's `/api/srcresults` (mobile.bg's public site is
server-rendered classic CGI; `api.mobile.bg` is a CORS-locked native-app backend with
non-discoverable endpoints).

## Goal & scope

- **Backfill:** a one-time deep ingestion bringing mobile.bg toward parity with the other
  sources.
- **Ongoing:** keep mobile.bg's full catalog fresh going forward.
- **Scope:** cars only (`avtomobili-dzhipove`), matching the other three sources and the
  app's search surface. Other vehicle categories are out of scope.

## Chosen approach — slug segmentation (Approach A)

Break the per-query cap by issuing many **narrower** queries (per make, descending to model
for large makes), each returning its own ≤151-page window. Union covers the full catalog.
This is the same strategy `SweepAutoBgListingsCommand` uses for auto.bg.

Key enabling facts (empirically verified 2026-07-09):

- The modern slug URL `https://www.mobile.bg/obiavi/avtomobili-dzhipove/{make}[/{model}]`
  **renders the same `.ads2023 .item` result cards** the current parser already reads.
- It paginates via a **`/p-{N}` suffix** (e.g. `/bmw/p-2`).
- Per-slug pagination hits the **same ~150-page cap** (`bmw/p-140` has cards, `bmw/p-160`
  is empty), so large makes (e.g. BMW ≈ 17,323 listings) must descend into models.

Approaches B (sitemap URL enumeration + per-listing detail fetch) and C (hybrid) were
rejected: B needs a brand-new detail-page parser and hundreds of thousands of requests for
ingestion; C doubles the surface area. Sitemaps remain available if we later want a cheaper
removal reconcile.

## Slug source — mobile.bg browse-sitemap (not our catalog)

We do **not** reuse `car_makes` / `car_models` slugs. Those are auto.bg-derived and do not
match mobile.bg's URL conventions. Verified mismatches (2026-07-09):

| Our slug | mobile.bg | Correct mobile.bg slug |
|---|---|---|
| `volkswagen` | 404 | `vw` |
| `volkswagen/golf` | 404 | `vw/golf` |
| `mercedes-benz/c` | 404 | numeric (e.g. `220`) |
| `mercedes-benz/e` | 404 | (not the letter) |
| `bmw/116`, `audi/a4` | 200 | (coincidentally match) |

Using our catalog would **silently drop Volkswagen entirely** and **most Mercedes models** —
exactly the large makes that need descent. It would also miss any make/model mobile.bg
carries that our catalog lacks.

Instead, the authoritative slug source is mobile.bg's own browse-sitemap:

```
https://www.mobile.bg/sitemap/sitemap-avtomobili-dzhipove-avtomobili-dzhipove.xml.gz
```

~168 KB gzipped, ~2,006 URLs of the form `/avtomobili-dzhipove/{make}` (depth 1 = make) and
`/avtomobili-dzhipove/{make}/{model}` (depth 2 = model). Parsed into a
`make => [model slugs]` map.

**Lifecycle:** parsed **fresh once per sweep by the command only** (weekly + one backfill
run). The queued jobs receive concrete slug strings and never fetch the sitemap. At weekly
cadence this is one trivial request, always current, no storage or staleness. (Caching or a
persisted catalog table were considered and deferred as unnecessary — YAGNI.)

## Components

1. **`MobileBgScraper::scrapeSegment(string $slug, int $page = 1): array`**
   Fetches `/obiavi/avtomobili-dzhipove/{slug}/p-{page}` (page 1 omits the `/p-1` suffix)
   and returns the same scraped-listing shape as `scrape()`. Shares card-parsing with the
   existing flat scraper by extracting a private `parseCards(Crawler): array` helper; the
   existing `scrape(int $page)` (flat CGI) is refactored to call it and otherwise unchanged.

2. **`MobileBgScraper::fetchMakeModelSlugs(): array<string, list<string>>`**
   Downloads + gunzips + parses the browse-sitemap into a `make => [model slugs]` map.
   Distinguishes make vs model by URL path depth.

3. **`ScrapeListingJob`** — extended with an optional `?string $path = null` constructor
   argument. When `$path` is set, `handle()` calls `scrapeSegment($path, $i)` instead of
   `scrape($i)`. `persistRecords()` is reused verbatim (source-agnostic: dedup,
   `published_at`-from-ID, make/model resolution, upsert, `checked_at`). Fully
   backward-compatible with all existing callers.

4. **`ScrapeMobileBgCatalogCommand`** (signature `scrape:mobilebg-catalog`)
   The segmented driver. Parses slugs, enumerates segments with descent, dispatches
   `ScrapeListingJob`s onto the `scrape-listings` queue. Serves both the manual backfill and
   the weekly schedule. Optional `--limit` / make filter option for testing a single make.

5. **`config/listings.php` → `mobilebg_sweep`** block: `page_cap` (~150), `pause_ms`,
   `connect_timeout`, `request_timeout`, `chunk_size` — mirroring the existing
   `autobg_sweep` block. No `env()` outside config.

## Segmentation / descent logic

Mirrors `SweepAutoBgListingsCommand::enumerateLiveIds`, adapted to dispatch ingestion jobs
rather than collect IDs:

```
slugs = fetchMakeModelSlugs()          # { vw: [golf, passat, ...], bmw: [116, ...], ... }

for each make in slugs:
    page the make-level slug from p-1 upward, dispatching a ScrapeListingJob per page-chunk,
    until a page returns < 20 cards (natural end)  OR  page_cap is reached
    │
    └─ if page_cap reached (make truncated, e.g. bmw):
           stop make-level paging and descend:
           for each model of that make:
               page {make}/{model} the same way (p-1 → natural end or cap)
```

- **Truncation detection is empirical** (reaching `page_cap`), not parsed from the cp1251
  meta count — more robust, no encoding parsing on the decision path.
- **Model-still-truncated fallback:** if an individual model also reaches the cap (rare,
  would require >~3k of one model), scrape its first ~150 pages and **log a warning naming
  the segment**. No further year/price splitting in v1 (YAGNI); the log surfaces it if it
  ever matters.
- **Dispatch, don't scrape inline:** the command enumerates and queues chunked
  `ScrapeListingJob`s; fetching/parsing/persisting runs through the existing queue. The
  existing per-page `sleep(2)` in the job provides request politeness.

### Data flow

```
scrape:mobilebg-catalog
  └─ fetchMakeModelSlugs()                         # 1 sitemap fetch
  └─ per segment: dispatch ScrapeListingJob(path: slug, from_page, to_page)
        └─ job: scrapeSegment(slug, p) → parseCards() → persistRecords()
              └─ Deduplication::make + make/model resolve + upsert + checked_at
```

## Error handling & safety

- **Sitemap fetch fails / empty:** command logs an error and **aborts** (does not proceed
  with an empty slug list, which would silently scrape nothing). Non-zero exit.
- **Segment page non-2xx / connection error:** `scrapeSegment` returns `[]` (same contract
  as the existing `scrape()`); the job simply persists nothing for that page. Job retry
  policy (`$tries = 5`, backoff) already covers transient failures.
- **windows-1251 encoding:** the slug pages declare `charset=windows-1251`. `scrapeSegment`
  must build the `Crawler` so Cyrillic decodes correctly (pass the charset / use
  `Crawler::addHtmlContent($html, 'windows-1251')` or convert to UTF-8 before parsing).
  Covered by a dedicated test asserting a known Cyrillic title parses intact. *(The existing
  flat CGI path already decodes correctly; this risk is specific to the slug pages.)*
- **Make with no models in sitemap but truncated:** log a warning and accept make-level cap
  coverage (can't descend without model slugs).
- **Idempotency:** re-running the sweep is safe — `persistRecords` upserts by fingerprint;
  duplicates merge, `checked_at` refreshes.

## Removal / cleanup interplay

Unchanged mechanically, but note the volume shift: as mobile.bg grows from ~2k toward
~150k+, `listings:clean-up-removed` (rolling 5,000/run hourly, oldest `checked_at` first)
will take proportionally longer for one full pass. mobile.bg removal detection itself is
already correct (genuine 404 = removed; transient/blocked = skipped). No change required in
v1; flagged for monitoring. mobile.bg listings ingested by the sweep get `checked_at` set at
persist time, so they enter the rolling probe queue naturally.

## Scheduling & backfill

- **Backfill:** run `php artisan scrape:mobilebg-catalog` once, manually, after deploy.
- **Ongoing:** `Schedule::command(ScrapeMobileBgCatalogCommand::class)->weekly()` at an
  off-peak time, `->withoutOverlapping()`. Keep the existing daily/30-min flat
  `scrape:new-listings` mobile.bg pass for fast pickup of brand-new listings.

## Testing plan (Pest, feature tests with HTTP faked)

- `scrapeSegment` builds the correct `/p-{N}` URL (page 1 has no suffix) and parses
  `.ads2023 .item` cards into the expected shape from a fixture HTML page.
- `scrapeSegment` decodes windows-1251 — a known Cyrillic title/location parses intact.
- `scrapeSegment` returns `[]` on non-2xx.
- `fetchMakeModelSlugs` parses a fixture gzipped sitemap into the correct `make => [models]`
  map, distinguishing make (depth 1) from model (depth 2).
- `ScrapeListingJob` with `path` set calls `scrapeSegment` and persists via the existing
  path; without `path` it still calls `scrape` (regression).
- `ScrapeMobileBgCatalogCommand` dispatches the expected jobs: a small make (no descent) and
  a large make faked to truncate at cap (asserts descent into model segments). Use
  `Bus::fake()` / `Queue::fake()` and assert dispatched job args.
- Command aborts (no jobs dispatched, error logged) when the sitemap fetch fails.

## Non-goals (v1)

- No per-year/price sub-splitting below model level (logged fallback only).
- No mobile.bg catalog table or slug caching (parse fresh per sweep).
- No detail-page parser / sitemap URL enumeration.
- No changes to other sources' scrapers or to the cleanup command's mechanics.
- No expansion beyond cars.