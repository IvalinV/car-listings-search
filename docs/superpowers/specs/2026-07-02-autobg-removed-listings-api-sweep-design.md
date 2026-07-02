# auto.bg Removed-Listings API-Diff Sweep — Design

**Date:** 2026-07-02
**Status:** Draft (pending review)

## Problem

We hold ~20,371 auto.bg listings. The generic `listings:clean-up-removed` command
confirms removals by issuing one HTTP request per source URL (least-recently
`checked_at` first, 5000 per hourly run). At auto.bg's scale this is the dominant
cost of the sweep and the population turns over slowly.

auto.bg now exposes a JSON search API (`/api/srcresults/{page}?slug=...`) that
lists live adverts with a stable `seo_id`. We can enumerate the set of currently
live auto.bg `seo_id`s far more cheaply than probing every stored URL, and use
that to stop wasting probes on listings the API already confirms are alive.

## Correctness model (decided)

- **API presence is authoritative only for "alive."** A `seo_id` present in the
  enumerated live set means the listing is live now.
- **Absence is a candidate, never a deletion.** Removal is still confirmed by the
  existing per-URL probe (`AutoBgScraper::isRemovedFromResponse`), preserving
  today's guarantees: transient/blocked responses are classified `unknown` and
  the listing is retried rather than falsely removed.

We never delete a listing on API evidence alone. This keeps a single source of
truth for removal semantics (the existing `resolveListing` logic).

## Architecture

### The integration hinge: `checked_at`

The existing `CleanUpRemovedListingsCommand` already orders its probe batch by
`checked_at ASC NULLS FIRST`. That ordering is the entire integration surface.

The new sweep does exactly one thing to the database: it **bulk-sets
`checked_at = now()` for every auto.bg listing whose `seo_id` the API confirms
live.** Consequences:

- Live auto.bg listings get a fresh `checked_at` → they sort to the *back* of the
  probe queue and are never wastefully probed.
- The *absent tail* (removal candidates) keeps its old `checked_at` → sorts to the
  *front* → gets probed promptly by the unchanged hourly command, which applies
  its proven multi-source resolution (delete only when *all* `source_urls` are
  gone; prune when partial).

No new table, no cache, no pre-filter branch inside the probe pool. `checked_at`
is itself the freshness signal.

**Self-healing:** if the sweep stops running, previously-bumped listings age
naturally and re-enter the probe queue. Behavior degrades exactly to today's.

**Bounded lingering:** a listing removed on auto.bg *after* a sweep bumped it
keeps its fresh `checked_at` until the next sweep declines to bump it; then it
ages and is probed. Worst-case lingering ≈ sweep interval (daily) + probe latency.

### New command: `listings:sweep-autobg`

Scheduled **daily**. Two phases.

**Phase 1 — Enumerate the live set.**

For each auto.bg make in the `car_makes` catalog:

1. Fetch page 1 of `/api/srcresults/1?slug=/avtomobili-dzhipove/{make}/page/1`.
2. Read `data.lastpage`. Page through `2..lastpage` (capped at 100), collecting
   `seo_id` for every advert with `active == 1`.
3. **Truncation descent:** if `lastpage == 100` the make feed is truncated at the
   API's 2000-item cap. Descend into that make's catalog models
   (`car_models` where `car_make_id` = make), enumerating each model slug
   (`.../{make}/{model}/page/{n}`) instead — models rarely exceed the cap, giving
   complete coverage. If `lastpage < 100`, the make feed is complete; skip models.

Paging a segment stops at its first failed page; ids already collected are kept.
This is safe **because reconciliation only ever bumps `checked_at`, never
deletes** — a missed page merely leaves its listings for the hourly probe. There
is no false-removal risk from partial enumeration.

**Phase 2 — Reconcile.**

Chunk over auto.bg `CarListing`s (those with an auto.bg URL in `source_urls`):

1. Extract the auto.bg `seo_id` from the stored URL via `#/obiava/(\d+)#`.
2. If that `seo_id` is in the live set, collect the listing id.
3. Bulk `update(['checked_at' => now()])` the collected ids in chunks.

Listings whose `seo_id` is absent (or unparseable) are left untouched — the
hourly command owns their confirmation and removal.

### Unchanged: `CleanUpRemovedListingsCommand`

No code change. It naturally focuses its probe budget on the absent auto.bg tail
plus the other three sources, because the sweep has pushed all live auto.bg
listings to the back of the `checked_at` queue.

## Data flow

```
sweep-autobg (daily)
  ├─ enumerate makes → (truncated? descend to models) → live seo_id set
  └─ reconcile: DB auto.bg listings whose seo_id ∈ live set → checked_at = now()

clean-up-removed (hourly, unchanged)
  └─ probe least-recently-checked → absent auto.bg tail confirmed & removed
```

## Matching contract

- Stored auto.bg URLs have the form `https://www.auto.bg/obiava/{seo_id}/{slug}`.
- API adverts expose `seo_id` (also the numeric id in advert `url`).
- Match on the numeric `seo_id` extracted with `#/obiava/(\d+)#`.

## Configuration

Add a `listings.autobg_sweep` block mirroring the existing `listings.cleanup`
pacing knobs (no new concepts):

- `pool_concurrency` — concurrent enumeration requests in flight.
- `pool_pause_ms` — pause between pool chunks to bound per-host rate.
- `pool_connect_timeout`, `pool_timeout` — per-request timeouts.
- `page_cap` — `100` (the API's hard pagination cap / truncation threshold).
- `chunk_size` — DB reconciliation chunk size (e.g. 500).

## Error handling

- **Failed request within a segment:** paging that segment stops; ids collected
  before the failure are kept. The unreached listings simply aren't bumped → they
  fall to the hourly probe. Safe because reconciliation never deletes on absence.
- **Model exceeds the 2000 cap:** its tail is absent from the live set → probed by
  the hourly command → found alive → `checked_at` bumped by the probe. Costs a few
  extra probes; self-correcting.
- **Make with no catalog models that truncates:** accept the tail → probe
  fallback. (The catalog gap is concentrated in smaller makes.)
- **Whole sweep fails / does not run:** self-healing as described above.

## Testing

Feature tests (Pest), faking the API with `Http::fake`:

1. **Enumeration collects active seo_ids across pages.** Fake a make feed with
   `lastpage` > 1; assert all `active == 1` seo_ids collected, inactive skipped.
2. **Truncation descent.** Fake a make feed with `lastpage == 100`; assert the
   command enumerates that make's catalog models instead.
3. **Reconciliation bumps only present listings.** Seed auto.bg listings (some
   with seo_ids in the faked live set, some not); assert `checked_at` bumped for
   present, untouched for absent.
4. **Segment failure is safe.** Fake a mid-paging error for one segment; assert
   its listings are *not* bumped (excluded from the live set).
5. **Non-auto.bg / unparseable URLs ignored.**

## Out of scope

- Changing the hourly `CleanUpRemovedListingsCommand`.
- Applying the API-diff to other sources.
- Using the API `active` flag for anything beyond enumeration filtering.
- Backfilling a persisted live-set table (deliberately avoided; `checked_at` is
  the freshness signal).
