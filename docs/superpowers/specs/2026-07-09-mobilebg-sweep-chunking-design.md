# mobile.bg sweep: per-page chunking (fix job timeouts)

**Date:** 2026-07-09
**Status:** Approved — open questions resolved (see bottom); implemented 2026-07-10.

## Problem

The full-catalog sweep (`scrape:mobilebg-catalog`, see
[2026-07-09-mobilebg-full-coverage-design.md](2026-07-09-mobilebg-full-coverage-design.md))
stalls: big-make jobs never finish, time out, get killed/retried, and never
dispatch their model children — so the queue freezes and the catalog barely grows.

### Root cause (diagnosed 2026-07-09, evidence-based)

`SweepMobileBgSegmentJob` loops **all ~150 pages of a make inside a single job**,
persisting ~3,000 records in one run. Measured against the remote laravel.cloud DB:

| Measurement | Value |
|---|---|
| DB round-trip (local → laravel.cloud eu-central) | **397 ms/query** |
| Queries per record in `ListingPersister::persist()` | **5.7** (uncached) |
| Persist one 20-record page | **~41.7 s** |
| Projected persist for a 150-page make (bmw) | **~6,260 s** |
| Job / worker timeout | 600 s |

Each big make needs ~100 min of persist but is killed at 10 min →
`TimeoutExceededException` → worker restart → retry (observed climbing `attempts`:
bmw=2, audi=3) → times out again, forever. Small makes finish; big makes never
complete, so they never descend → queue stuck at ~139 pending.

### Why the existing scrapers don't have this problem

`ScrapeNewListingsCommand` dispatches `ScrapeListingJob` with **`chunkSize = 1` —
one job per page** (`from_page`/`to_page`), `timeout = 180`. Each job persists ~20
records and finishes well within its timeout. **Same shared `ListingPersister`,
same per-record cost** — the difference is purely job granularity. The segment job
broke the pattern by putting a whole make in one job.

**Decision: the fix is to chunk the segment sweep to one page per job, matching the
existing pattern. The persister is NOT changed** (per-record N+1 is pre-existing and
fine at cloud latency; out of scope here).

## Constraints (decided)

- **Execution: on laravel.cloud** (co-located with DB, ~sub-ms query latency), where
  per-page persist is ~1 s. Local runs are spot-testing only. Chunking keeps every
  job bounded regardless.

## Chosen approach — A: self-chaining page-walker

One job = one page. Each job scrapes+persists its page, then dispatches the next
step. No upfront page count, no wasted requests, descent falls out naturally,
bounded jobs that can never time out. (Approaches B "discovery + fan-out" and C
"reuse ScrapeListingJob + $path with blind ranges" were rejected: B adds a second
job type and double-fetches; C over-dispatches ~21k mostly-empty jobs and has no
clean descent home.)

## Components

### 1. `SweepMobileBgSegmentJob` — reshaped into a per-page walker

```php
public function __construct(
    public string $slug,          // "bmw" or "bmw/x5"
    public int $page = 1,
    public array $childSlugs = [], // model slugs to descend into; only on a make seed
) {}

public int $timeout = 120;  // one page is ~1-3s on cloud; generous even at 397ms
public int $tries = 5;
public function backoff(): array { return [30, 60, 120]; }
```

`handle(MobileBgScraper $scraper, ListingPersister $persister)`:

1. `$records = $scraper->scrapeSegment($this->slug, $this->page);` — **throws** on
   non-2xx (retryable via `tries`).
2. `if ($records === []) { return; }` — a 200 response with no cards = natural end.
3. `$persister->persist($records, $scraper);`
4. Continuation (dispatched **last**, only after a successful persist):
   - `if ($this->page >= $pageCap)`:
     - `childSlugs` non-empty → dispatch one walker per model:
       `SweepMobileBgSegmentJob::dispatch("$slug/$model", 1, [])->onQueue('scrape-listings')`.
     - `childSlugs` empty → `Log::warning("... reached the page cap with no models to
       descend into; coverage may be truncated.")`.
     - stop.
   - `else` → dispatch `SweepMobileBgSegmentJob::dispatch($slug, $page + 1, $childSlugs)`.

### 2. `MobileBgScraper::scrapeSegment()` — split "error" from "empty"

Change the non-2xx branch from `return []` to **throw** (e.g. `RuntimeException` /
`RequestException`) so the walker retries the same page instead of treating a server
hiccup as the end of results. A 200 response that parses to no cards still returns
`[]`. windows-1251 decoding unchanged.

### 3. `ScrapeMobileBgCatalogCommand` — seed one page-1 walker per make

```php
SweepMobileBgSegmentJob::dispatch($make, 1, array_values($models))->onQueue('scrape-listings');
```
Otherwise unchanged (sitemap fetch, abort-on-empty, `--make` filter).

## Data flow

```
scrape:mobilebg-catalog
  └─ fetchMakeModelSlugs()                      # 1 sitemap fetch
  └─ per make: dispatch walker(make, 1, models)
        └─ walker(make, p): scrapeSegment → persist
              ├─ full page  → dispatch walker(make, p+1, models)
              ├─ p == cap   → dispatch walker(make/model, 1, []) × each model
              └─ empty page → stop
```

Pages within a make are serial (naturally polite); the 143 makes run in parallel
across workers.

## Error handling & idempotency

- **Non-2xx** → throw → same page retried via `tries`/backoff. A transient blip never
  truncates a make (a key improvement over the monolithic job).
- **At-least-once delivery:** if a worker dies after persist + dispatch-next but
  before ack, the page reruns (persist is an idempotent upsert by fingerprint) and
  re-dispatches its continuation → a rare duplicate walk, harmless (wasted requests
  only, no data corruption).
- **Model still truncated** (a single model exceeds the cap) → log a warning naming
  the segment; no year/price sub-splitting (v1 YAGNI).

## Config & worker settings

- `config('listings.mobilebg_sweep.page_cap')` stays 150. `pause_ms` is no longer
  needed inline (queue throughput + serial-per-make paces requests); drop it or keep
  a tiny pre-dispatch `usleep`. Keep `connect_timeout` / `request_timeout`.
- `DB_QUEUE_RETRY_AFTER=700` (already in `.env` / `.env.example`) stays comfortably
  above the 120 s job timeout.
- **Worker command must be consistent:** `--timeout` below `retry_after`, e.g.
  `--timeout=120`. The current mismatch (one worker `--timeout=600`, one `--timeout=3200`;
  3200 > 700 reintroduces duplicate reservations) gets standardized. Document the
  canonical worker command near the scheduled command.

## Testing plan (Pest, HTTP faked, `Bus::fake`)

- Full page → dispatches `walker(slug, page+1)` with propagated `childSlugs` (assert args).
- Empty (200, no cards) page → dispatches nothing, persists nothing.
- Reaches `page_cap` with `childSlugs` → dispatches one walker per model, page 1, empty children.
- Reaches `page_cap` with no `childSlugs` → logs the truncation warning, dispatches nothing.
- `scrapeSegment` **throws** on non-2xx (retryable) and returns `[]` on 200-with-no-cards.
- Child walker (`bmw/x5`) at cap with empty `childSlugs` does not re-descend.
- windows-1251 title parses intact (kept from existing tests).

## Open questions — RESOLVED (2026-07-10)

1. **Rename the job?** RESOLVED: renamed `SweepMobileBgSegmentJob` →
   `SweepMobileBgPageJob`. The job is now per-page, so the name matches its intent;
   all references (command, tests, self-dispatch) updated.
2. **Keep `WithoutOverlapping`?** RESOLVED: kept a lightweight per-page guard,
   `WithoutOverlapping("mbg:$slug:$page")->dontRelease()`, as cheap insurance against
   double-chaining. Dropped the `->expireAfter(700)` (jobs are now ~1-3s; the default
   lock TTL is fine).

## Out of scope

- No change to `ListingPersister` (per-record N+1 is pre-existing; fine at cloud latency).
- No detail-page parser, no sitemap URL enumeration, no other sources.
- No year/price sub-splitting below model level.