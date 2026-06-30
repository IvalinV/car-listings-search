# Rolling Inactive-Listing Cleanup — Design

**Date:** 2026-06-30
**Status:** Approved (pending spec review)

## Problem

Listings removed from their source sites are not being deleted. Concrete case:
`/cars/4067325` was removed from both sources it was scraped from, the cleanup
command ran, and it is still live on the site.

### Root cause

`CleanUpRemovedListingsCommand` is scheduled `weeklyOn(2, 0)` and is a single
serialized loop that probes **every** source URL of **every** listing with a
`sleep(1)` after each request. With **158,619 listings** (~59,333 multi-source →
**~218,000 URL probes**), one full pass needs **~60+ hours** of wall-clock. On
laravel.cloud the process is killed long before completing, so the sweep **never
finishes a pass** and most listings — including `4067325` — are never reached.

The per-source removal *detection* is already correct (mobile.bg 404s, cars.bg →
`status_page.php`, auto.bg → `/obiavi/` category, car24 → API `advert: null`).
The failure is purely one of **scale and completion**, not detection.

## Constraints (why the obvious fixes were rejected)

- **Probe everything, parallelized** (one mega-job, or fan-out of dozens of
  jobs): ~218k requests/pass is unmanageable, long jobs fail, fan-out is hard to
  recover on failure, and coverage is still not guaranteed. ✗
- **Re-scrape & set-difference:** sources cap at ~100 pages, but our 158k
  listings accumulated over time and most now sit far deeper in each catalog (or
  are already gone). A bounded re-scrape cannot reach the full set. ✗
- **"Delete anything old" heuristics:** would wrongly delete still-live aged
  listings. Not authoritative. ✗

For a listing buried deep in a source catalog there is **no cheaper
authoritative liveness signal than probing its URL**. The design therefore does
not try to avoid probing old listings — it makes that probing **bounded,
concurrent, rolling, and self-healing** so it actually completes.

## Goals

- Reliably delete listings that have been removed from all their sources, within
  a bounded staleness window (~1–2 days).
- Every listing — old or new — is covered on every cycle; nothing is permanently
  left out.
- Each scheduled run is short and bounded so it always completes before any
  platform timeout.
- Transient network/source errors never cause a false deletion.

## Non-goals

- No change to removal semantics: keep the existing **hard `delete()`** (no soft
  delete / `is_active` flip).
- No change to per-source detection logic (already correct).
- No re-scraping strategy changes beyond stamping liveness (below).

## Design

### 1. Schema — `checked_at`

Add a nullable `checked_at` timestamp to `car_listings`, meaning "last time this
listing was confirmed alive." Add an index on `checked_at` (the sweep orders by
it). Existing rows backfill to `NULL`, which sorts first (see §3), so the first
cycle probes the entire backlog.

### 2. Free liveness from scraping (optimization, not the coverage mechanism)

`ScrapeListingJob::persistRecords()` already matches each scraped (live) record
to its row by `fingerprint` and upserts it. Add `'checked_at' => now()` to that
upsert array. Because `upsert(..., 'fingerprint')` updates all supplied columns
on conflict, every listing the scrape touches is stamped alive on both insert
and update.

This **only** spares the freshest listings from being re-probed. It is **not**
how old listings are covered — that is §3.

### 3. Rolling probe sweep (rewrite of `CleanUpRemovedListingsCommand`)

Keep signature `listings:clean-up-removed`; add `--limit` (default from config).

Each run:

1. **Select the batch** — the `--limit` listings with the oldest `checked_at`:
   `orderByRaw('checked_at ASC NULLS FIRST')->limit($limit)`. An old listing not
   in any scrape range has the oldest/`NULL` `checked_at`, so it sorts to the
   **front** and is probed **first**. Old listings are prioritized, not skipped.
2. **Probe concurrently** — gather every `(url, scraper)` pair in the batch and
   issue them through `Http::pool`, processed in **concurrency-capped chunks**
   (default 25 in flight) with a per-host cap so no single source is hammered.
   This replaces the serial `sleep(1)`.
3. **Classify each URL** as `removed | alive | unknown` (see §4).
4. **Act per listing:**
   - any `unknown` → **skip** (leave `checked_at`; retried next cycle).
   - else all `removed` → **hard `delete()`**.
   - else mix of `removed`/`alive` → prune `source_urls` to the alive subset,
     set `checked_at = now()`.
   - else all `alive` → set `checked_at = now()`.

The rolling cursor guarantees eventual full coverage; failures self-heal because
`checked_at` only advances on a definitive result.

### 4. Per-source probing refactor

To probe concurrently via `Http::pool`, each scraper must expose its request and
its interpretation as separate halves (today they are fused inside
`isListingRemoved`). Add to `ScraperInterface` / `Scraper`:

- `removalProbeUrl(string $url): string` — the URL to GET. Default returns
  `$url`; `Car24Scraper` returns its mobile-API URL with `ida`/`title` query
  params.
- `removalProbeOptions(): array` — per-source request options
  (`allow_redirects => false` for cars.bg/auto.bg/car24; `Accept: application/json`
  for car24; default for mobile.bg).
- `isRemovedFromResponse(Response $response, string $url): bool` — the existing
  interpretation (404 / redirect target / `advert` null), moved out of
  `isListingRemoved`.

`isListingRemoved` is kept as a thin sequential wrapper calling both halves, so
existing callers/tests still work.

**Classification rule in the command:** a pooled entry is `unknown` when it is a
connection exception, a 5xx, or a 429 (transient) — never treated as removed.
Otherwise it is `removed`/`alive` per `isRemovedFromResponse`.

### 5. Scheduling & config

- `routes/console.php`: change the cleanup schedule from `weeklyOn(2, 0)` to
  `hourly()` with `->withoutOverlapping()` so slow runs never stack.
- Locked-in defaults: **`--limit=5000`, hourly** → ~11–12-min runs (measured),
  full **~1.3-day** cycle. Add a config file (new `config/listings.php`, following
  Laravel config conventions; values read via `config()`, never `env()` outside
  config) holding: `cleanup.batch_limit` (5000), `cleanup.pool_concurrency` (25),
  `cleanup.pool_pause_ms` (250).
- **Per-host politeness** is achieved without a separate semaphore: before
  chunking, probes are **round-robin interleaved by host**, so each
  `pool_concurrency`-sized chunk is spread across the (up to 4) hosts rather than
  hammering one. Chunks are processed sequentially, bounding total in-flight
  requests to `pool_concurrency`.

### Timing basis

| Per run | Frequency | Run wall-clock | Full cycle |
|--------:|-----------|---------------:|-----------:|
| 2,000   | hourly    | ~5 min         | ~3.3 days  |
| **5,000** | **hourly** | **~11–12 min** | **~1.3 days** |
| 10,000  | hourly    | ~24 min        | ~16 hours  |

Per-run wall-clock is **measured** (two production runs of `--limit=5000` took
~11 and ~12 min); the 2k/10k rows scale that linearly. Real throughput is
~9–10 probes/s (slower sources than first assumed, plus the 250 ms inter-chunk
pause). The **full-cycle** figures are unchanged: they depend on listings
processed per day (hourly × 5000 = 120k/day), and a ~12-min run still completes
comfortably within its hour. To shorten a run without changing the cycle, raise
`pool_concurrency` or lower `pool_pause_ms`. **First cycle is heaviest** (all
158k rows have `NULL checked_at` → the full backlog is probed once over ~1.3
days); steady-state is lighter because scrape-stamping keeps fresh listings out.

## Data flow

```
scrape (daily/30-min) ── matches live listing by fingerprint ──> upsert checked_at = now()
                                                                        │
listings:clean-up-removed (hourly, limit 5000)                          │
  └─ pick 5000 oldest checked_at (NULLS FIRST) ◄───────────────────────┘
       └─ Http::pool probe their source_urls (chunked, per-host capped)
            └─ classify removed | alive | unknown
                 ├─ all removed         → delete()
                 ├─ partial removed     → prune source_urls, checked_at = now()
                 ├─ all alive           → checked_at = now()
                 └─ any unknown         → skip (retry next cycle)
```

## Error handling

- **Transient (timeout / 5xx / 429 / connection exception):** classified
  `unknown`; the listing is skipped and retried next cycle. Never deleted or
  pruned on a transient.
- **A listing is deleted only when every one of its sources independently
  confirms `removed`.**
- The pool processes the batch in chunks; one failed request affects only its own
  listing's classification, not the run.

## Testing (Pest)

- **Unit (per scraper):** `isRemovedFromResponse` against `Http::fake` fixtures
  for removed, alive, and transient cases; `removalProbeUrl` / `removalProbeOptions`
  return the right shape (esp. car24's API URL).
- **Feature (command):** with `Http::fake`,
  - selects the oldest-`checked_at` batch and respects `--limit`;
  - deletes a fully-removed listing;
  - prunes `source_urls` for a partially-removed multi-source listing and bumps
    `checked_at`;
  - bumps `checked_at` for an all-alive listing;
  - **skips** (no delete, no prune, `checked_at` unchanged) when any source is
    transient/unknown.
- **Feature (scrape job):** `persistRecords` sets `checked_at` on both insert and
  update of an existing fingerprint.
- All tests use `Http::fake` — no real network.

## Rollout

1. Migration: add `checked_at` + index; existing rows = `NULL`.
2. Ship scraper refactor (§4) + command rewrite (§3) + scrape stamp (§2).
3. Switch schedule to hourly (§5).
4. First ~1.3-day cycle clears the existing removed-listing backlog; steady
   state thereafter.