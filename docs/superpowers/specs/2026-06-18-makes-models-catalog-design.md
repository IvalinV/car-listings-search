# Makes/Models Catalog — Design

**Date:** 2026-06-18
**Status:** Approved (pending spec review)

## Goal

Build a one-time Artisan command that scrapes the list of car makes from every
supported platform, deduplicates them into a clean canonical catalog, scrapes
models from a single reliable source, and stores everything in two new database
tables. Updating these lists on a schedule is explicitly out of scope for now.

## Decisions

- **Storage:** two normalized tables (`car_makes` → `car_models`).
- **Stored values:** clean, deduplicated names only — no per-platform identifiers.
- **Makes source:** all four platforms, pooled and deduplicated.
- **Models source:** auto.bg only (the single source that cleanly exposes a
  per-make model list).
- **Duplication:** none — canonical **full names** (e.g. `VW` collapses into
  `Volkswagen`), achieved via normalization plus a small alias map.
- **Command name:** `scrape:makes-models`.

## Data Model

### `car_makes`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | PK |
| `name` | string | **unique**, canonical full name |
| `slug` | string | URL-friendly form of `name` |
| `created_at`, `updated_at` | timestamp | |

### `car_models`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | PK |
| `car_make_id` | foreignId | FK → `car_makes.id`, cascade on delete |
| `name` | string | model display name |
| `slug` | string | URL-friendly form |
| `created_at`, `updated_at` | timestamp | |

- Unique composite index on `(car_make_id, name)`.
- Migrations follow the existing `YYYY_MM_DD_HHMMSS_*` naming convention.

### Models

- `App\Models\CarMake`
  - `models(): HasMany` → `CarModel`
- `App\Models\CarModel`
  - `make(): BelongsTo` → `CarMake`
- Both get factories (`CarMakeFactory`, `CarModelFactory`) per project convention.
  `CarModelFactory` creates an associated `CarMake` by default.

## Data Sources (verified 2026-06-18)

| Platform | Makes source | Format | Models |
|---|---|---|---|
| car24.bg | `GET https://api.car24.bg/mobile_api/brands` | JSON: `data.marki` (popular, `[name, slug]` pairs) + `data.markiOther` (`{brand, sef, count}`) | — |
| auto.bg | `GET https://www.auto.bg/obiavi/avtomobili-dzhipove` | HTML: brand links `/obiavi/avtomobili-dzhipove/{slug}` | **yes** |
| cars.bg | `GET https://www.cars.bg/` | HTML: `#brandsList` MDC chip labels | — |
| mobile.bg | `GET https://www.mobile.bg/` | HTML: `akSearchMarki` autocomplete menu items | — |

**Models (auto.bg):** `GET /obiavi/avtomobili-dzhipove/{makeSlug}` returns model
links `/obiavi/avtomobili-dzhipove/{makeSlug}/{modelSlug}`; the anchor text is
the model display name. (Verified: BMW returns 110 models.)

## Scraper Changes

All new methods are additive; existing `scrape()` / listing logic is untouched.
Requests reuse the existing `browserHeaders()` helper.

`scrapeMakes()` returns a uniform shape across all scrapers:
`array<int, array{name: string, slug: ?string}>`, where `name` is the raw
(pre-normalization) display name and `slug` is the platform's own make slug when
available. The slug is what lets the model crawl query auto.bg by its own
identifier — it cannot be derived from the canonical name (auto.bg's slug for
`Volkswagen` is `vw`).

- `Scraper` (base): add a default `scrapeMakes(): array` returning `[]`.
- `Car24Scraper::scrapeMakes(): array` — decode JSON, merge `marki` + `markiOther`;
  `slug` from each brand's `sef`.
- `AutoBgScraper::scrapeMakes(): array` — parse brand links from the category page;
  `slug` from the link path.
- `AutoBgScraper::scrapeModels(string $makeSlug): array` — fetch the per-make page,
  extract model names. Returns `array<int, array{name: string, slug: string}>`.
- `CarsBgScraper::scrapeMakes(): array` — parse `#brandsList` chip labels;
  `slug` may be `null` (not needed downstream).
- `MobileBgScraper::scrapeMakes(): array` — parse `akSearchMarki` menu items;
  `slug` may be `null`.

## Command: `scrape:makes-models`

Located in `app/Console/Commands/`, following existing command conventions.

1. Call `scrapeMakes()` on each of the four scrapers (resolved via the container);
   pool all returned `{name, slug}` entries. A failure on one platform is logged
   and skipped — the others still run.
2. **Normalize and deduplicate** the pooled names (see below); `upsert` into
   `car_makes` keyed on `name`.
3. For each auto.bg make (using its `slug`), call `AutoBgScraper::scrapeModels()`,
   dedupe the models within that make, and `upsert` into `car_models` linked to
   the matching `car_make` row. The match is found by normalizing the auto.bg
   make name through the same alias map used in step 2.
4. Output a summary: makes found vs. deduped, and model counts per make.

The command is idempotent (re-running upserts rather than duplicates), so it is
safe to run more than once.

## Normalization & Deduplication

The "remove duplication" requirement, producing canonical full names:

1. Trim and collapse internal whitespace; drop empty values.
2. Build a normalized key (lowercased, whitespace-collapsed) for comparison.
3. Apply a small **alias map** that maps known cross-platform variants and
   abbreviations to their canonical full name, e.g.:
   - `VW` → `Volkswagen`
   - `Mercedes Benz` → `Mercedes-Benz`
   - `Alfa` → `Alfa Romeo`
   (Seeded with obvious cases; unmatched oddities are logged for later extension.)
4. Deduplicate on the post-alias key, keeping the canonical display form.

The same trim/collapse normalization is applied to model names within each make.

## Testing (Pest)

- **Per-scraper unit/feature tests** using `Http::fake()` with saved fixture
  snippets (JSON for car24, HTML for the others), asserting `scrapeMakes()` and
  (auto.bg) `scrapeModels()` parse the expected names.
- **Command feature test**: fake all sources so the pooled set contains known
  duplicates and abbreviations; assert the command collapses them to canonical
  full names, that `car_makes` / `car_models` rows are created with correct
  relationships, and that a second run does not create duplicates (idempotency).

## Out of Scope

- Scheduled / incremental updates of the catalog.
- Per-platform make/model identifiers.
- Models from any source other than auto.bg.
- Wiring the catalog into search filters or the UI.