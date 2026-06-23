# Listing Make/Model Filtering — Design

**Date:** 2026-06-23
**Status:** Approved (pending spec review)

## Goal

Make every car listing searchable and filterable by make and model, with a hard
guarantee that **no active listing can be filtered into invisibility**. Add make
and model dropdown filters to the listings page and make the text search match
make/model reliably.

## Decisions

- **Storage:** add nullable foreign keys `car_make_id` and `car_model_id` to
  `car_listings`, linking to the existing `car_makes` / `car_models` catalog.
- **Catalog-driven, auto-grow:** a resolver parses make/model from each listing's
  title and links to the catalog, **auto-creating** any make/model it finds that
  is not yet in the catalog. The catalog therefore grows to 100% coverage of what
  listings actually contain.
- **Unknown model:** when the model can't be confidently identified, leave
  `car_model_id` null; the model dropdown surfaces these as "Unspecified".
- **Make safety net:** when a title is unparseable (empty/garbage), leave
  `car_make_id` null; the make dropdown also surfaces "Unspecified". This makes
  the coverage guarantee total.
- **UI:** make + model dropdown filters (model dependent on selected make) AND
  text search that also matches the linked make/model names.
- **No dropdown counts** in v1.

## Data Model

### Migration — add columns to `car_listings`

| Column | Type | Notes |
|---|---|---|
| `car_make_id` | `foreignId` nullable | FK → `car_makes.id`, `nullOnDelete`, indexed |
| `car_model_id` | `foreignId` nullable | FK → `car_models.id`, `nullOnDelete`, indexed |

Both nullable (a listing may resolve to make-only, or neither). Existing rows
start null and are populated by the backfill command.

### `CarListing` relationships

```php
public function make(): BelongsTo
{
    return $this->belongsTo(CarMake::class, 'car_make_id');
}

public function model(): BelongsTo
{
    return $this->belongsTo(CarModel::class, 'car_model_id');
}
```

(Foreign key named explicitly — Eloquent would otherwise derive `make_id` /
`model_id` from the method name.)

## Resolver — `App\Services\ListingMakeModelResolver`

The heart of the coverage guarantee. Turns a listing title into a `CarMake` and
an optional `CarModel`, creating catalog rows as needed.

```php
public function resolve(?string $title): array; // ['make' => ?CarMake, 'model' => ?CarModel]
```

Algorithm:

1. **Normalize the title:** trim, collapse whitespace. Empty → `['make' => null,
   'model' => null]`.
2. **Make (longest-prefix match):** build a lookup of existing `car_makes` keyed
   by a loose key (lowercased, non-alphanumerics stripped) plus their
   `MakeNormalizer` canonical form. Test the title's leading 1- and 2-word
   prefixes (longest first) so multi-word makes ("Alfa Romeo", "Land Rover",
   "Aston Martin") and aliases ("VW" → Volkswagen) match. On a hit, use that
   `CarMake`. On a miss, canonicalize the first token via `MakeNormalizer` and
   `firstOrCreate` a new `CarMake` (slug = `Str::slug(name)`).
3. **Model:** take the first whitespace token after the matched make text.
   - Reject engine-spec noise: tokens matching `/^\d+([.,]\d+)/` (e.g. `2.8`,
     `2.4i`) or empty remainder → model stays null.
   - Otherwise `firstOrCreate` the model under the make (match existing models
     case-insensitively first; slug = `Str::slug(name)`).
4. Return the resolved make/model.

The resolver is pure with respect to its inputs and idempotent: the same title
always resolves to the same catalog rows, and re-running creates nothing new.

`MakeNormalizer` is reused for alias canonicalization (VW → Volkswagen, etc.).

## Population

### Backfill command — `listings:resolve-makes-models`

- Iterates all `car_listings` (chunked), runs each title through the resolver,
  and saves `car_make_id` / `car_model_id`.
- Idempotent — safe to re-run; re-resolves to the same rows.
- Reports totals: listings processed, makes/models resolved, how many ended up
  make-null and model-null.

### Ingestion — `ScrapeListingJob`

- Each scraped record's title is run through the resolver before/at upsert time,
  and `car_make_id` / `car_model_id` are written alongside the other fields, so
  new and updated listings are covered automatically.
- Resolution happens per record inside `persistRecords()`; the resolved ids are
  added to the upsert payload.

## Search & Filters — Livewire `car-listings`

### New filter state

- `#[Url] public ?string $make = null;` — selected make slug.
- `#[Url] public ?string $model = null;` — selected model slug (or the literal
  `unspecified`).
- Changing `make` resets `model` and pagination (existing `updated*` pattern).

### Dropdown options

- **Makes:** catalog makes that have ≥1 active listing, ordered by name, plus an
  "Unspecified" option if any active listing has a null make.
- **Models:** when a make is selected, that make's models that have ≥1 active
  listing, ordered by name, plus "Unspecified" if any active listing under that
  make has a null model. Empty/ignored when no make is selected.

### Query additions (in the `listings()` computed)

```php
->when($this->makeId(), fn ($q, $id) => $q->where('car_make_id', $id))
->when($this->make === 'unspecified', fn ($q) => $q->whereNull('car_make_id'))
->when($this->modelId(), fn ($q, $id) => $q->where('car_model_id', $id))
->when($this->make && $this->model === 'unspecified',
    fn ($q) => $q->whereNull('car_model_id'))
```

(`makeId()` / `modelId()` translate the selected slugs to ids; helper methods on
the component.)

### Search

The existing title/description `LIKE` is extended to also match the linked make
and model names, so a search for the canonical name ("Volkswagen") finds listings
whose title uses an alias ("VW Passat"):

```php
->when($this->search, fn ($q) => $q->where(function ($q) {
    $q->where('title', 'like', "%{$this->search}%")
      ->orWhere('description', 'like', "%{$this->search}%")
      ->orWhereHas('make', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
      ->orWhereHas('model', fn ($q) => $q->where('name', 'like', "%{$this->search}%"));
}))
```

### View

Two dropdowns added to the existing filter panel, matching the markup/styling of
the current fuel/transmission selects (model select disabled until a make is
chosen).

## Coverage Guarantee (why nothing is left out)

- Every active listing has either a real make or a null make surfaced as
  "Unspecified" — so it always appears under some make-filter value.
- Selecting a make with no model filter returns all of that make's listings,
  including null-model ones.
- "Unspecified" model option returns the null-model listings for the chosen make.
- Therefore every active listing is reachable through the filters; the Livewire
  test asserts this explicitly.

## Testing (Pest)

**Resolver unit tests** (`tests/Unit/ListingMakeModelResolverTest.php`):
- Simple: "BMW X5" → make BMW, model X5.
- Multi-word make: "Alfa Romeo 159" → make "Alfa Romeo", model "159".
- Alias: "VW Passat 2.8" → make Volkswagen, model Passat.
- Unknown model: "BMW" → make BMW, model null.
- Engine-noise rejection: "Fiat 2.0 JTD" → make Fiat, model null (not "2.0").
- Empty/garbage title → make null, model null.
- Auto-create: a never-seen make/model creates the catalog rows; a second call
  creates nothing new (idempotency).

**Backfill command test** (`tests/Feature/Commands/ResolveMakesModelsCommandTest.php`):
- Listings created via factory with titles; after running, each has the expected
  make/model; running twice does not duplicate catalog rows.

**Ingestion test** (extend `tests/Feature/Jobs/ScrapeListingJobTest.php`):
- A scraped record with a known title results in a listing with the correct
  `car_make_id` / `car_model_id`, auto-creating catalog rows.

**Livewire filter test** (`tests/Feature/CarListingsMakeModelFilterTest.php`):
- Coverage: seed listings (including a null-make and a null-model one); assert
  every active listing is reachable via some make-filter value, and that the
  "Unspecified" options surface the null cases.
- Make filter narrows results; model filter depends on make; search by canonical
  make name matches alias-titled listings.

## Out of Scope

- Dropdown counts (e.g. "BMW (142)").
- Re-mapping model-granularity mismatches between platforms (e.g. unifying
  auto.bg's "C 320" with a title's "C-Class").
- Reconciling makes that one platform treats as a model (e.g. Range Rover under
  Land Rover).
- Any change to how the catalog scraping command (`scrape:makes-models`) works.
