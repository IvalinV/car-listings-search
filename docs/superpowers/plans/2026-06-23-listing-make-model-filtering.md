# Listing Make/Model Filtering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every listing searchable and filterable by make and model, with a hard guarantee that no active listing can be filtered into invisibility.

**Architecture:** Add nullable `car_make_id` / `car_model_id` FKs to `car_listings`. A `ListingMakeModelResolver` parses each title into a catalog make + optional model, auto-creating catalog rows as needed (using the existing `MakeNormalizer` for aliases). A backfill command populates existing rows; `ScrapeListingJob` resolves new ones at ingestion. The `car-listings` Volt component gains make/model dropdowns (with "Unspecified" buckets) and make/model-aware search.

**Tech Stack:** Laravel 12, PHP 8.3, Livewire 4 (Volt single-file), Pest 4.

## Global Constraints

- PHP 8.3; explicit return types on every method; constructor property promotion in constructors.
- Curly braces on all control structures.
- Use `php artisan make:` generators (`--no-interaction`).
- Eloquent relationships with explicit foreign keys and return type hints; no raw `DB::`.
- `protected $guarded = [];` convention on models.
- Tests are Pest; DB tests add `uses(RefreshDatabase::class)`. Livewire tests call `Livewire::test('pages.car-listings')` and `->withoutVite()` in `beforeEach`.
- Run `vendor/bin/pint --dirty --format agent` before finalizing.
- The resolver is **catalog-driven**: multi-word make recognition (e.g. "Alfa Romeo") depends on that make existing in `car_makes` (true in production after `scrape:makes-models`); a novel multi-word make auto-creates from its first token only. This is acceptable — coverage is still preserved.

---

### Task 1: FK columns and relationships

**Files:**
- Create: `database/migrations/2026_06_23_070001_add_make_model_to_car_listings_table.php`
- Modify: `app/Models/CarListing.php` (add `make()`, `model()` relationships)
- Modify: `app/Models/CarMake.php` (add `listings()`)
- Modify: `app/Models/CarModel.php` (add `listings()`)
- Test: `tests/Feature/Models/ListingMakeModelRelationsTest.php`

**Interfaces:**
- Produces:
  - `car_listings.car_make_id` (nullable FK → `car_makes`), `car_listings.car_model_id` (nullable FK → `car_models`).
  - `CarListing::make(): BelongsTo`, `CarListing::model(): BelongsTo`.
  - `CarMake::listings(): HasMany`, `CarModel::listings(): HasMany`.

- [ ] **Step 1: Create the migration**

Run: `php artisan make:migration add_make_model_to_car_listings_table --no-interaction`

Rename the generated file to `database/migrations/2026_06_23_070001_add_make_model_to_car_listings_table.php`, then set its contents to:

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
            $table->foreignId('car_make_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('car_model_id')->nullable()->after('car_make_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('car_listings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('car_make_id');
            $table->dropConstrainedForeignId('car_model_id');
        });
    }
};
```

- [ ] **Step 2: Add relationships to `CarListing`**

In `app/Models/CarListing.php`, add these imports near the top (after the existing `use` lines):

```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;
```

Then add these methods inside the class (e.g. just before `scopeActive`):

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

- [ ] **Step 3: Add `listings()` to `CarMake` and `CarModel`**

In `app/Models/CarMake.php`, add `use App\Models\CarListing;` is not needed (same namespace). Add the import `use Illuminate\Database\Eloquent\Relations\HasMany;` (already present) and add:

```php
    /**
     * @return HasMany<CarListing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(CarListing::class, 'car_make_id');
    }
```

In `app/Models/CarModel.php`, add `use Illuminate\Database\Eloquent\Relations\HasMany;` and:

```php
    /**
     * @return HasMany<CarListing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(CarListing::class, 'car_model_id');
    }
```

- [ ] **Step 4: Write the failing test**

Create `tests/Feature/Models/ListingMakeModelRelationsTest.php`:

```php
<?php

use App\Models\CarListing;
use App\Models\CarMake;
use App\Models\CarModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links a listing to its make and model', function (): void {
    $make = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    $model = $make->models()->create(['name' => 'X5', 'slug' => 'x5']);

    $listing = CarListing::factory()->create([
        'car_make_id' => $make->id,
        'car_model_id' => $model->id,
    ]);

    expect($listing->make->name)->toBe('BMW')
        ->and($listing->model->name)->toBe('X5')
        ->and($make->listings)->toHaveCount(1)
        ->and($model->listings)->toHaveCount(1);
});

it('allows a listing with no make or model', function (): void {
    $listing = CarListing::factory()->create([
        'car_make_id' => null,
        'car_model_id' => null,
    ]);

    expect($listing->make)->toBeNull()
        ->and($listing->model)->toBeNull();
});
```

- [ ] **Step 5: Run the test**

Run: `php artisan test --compact --filter=ListingMakeModelRelationsTest`
Expected: PASS (2 passed).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_23_070001_add_make_model_to_car_listings_table.php app/Models/CarListing.php app/Models/CarMake.php app/Models/CarModel.php tests/Feature/Models/ListingMakeModelRelationsTest.php
git commit -m "feat: car_make_id/car_model_id FKs and relationships on listings"
```

---

### Task 2: ListingMakeModelResolver

**Files:**
- Create: `app/Services/ListingMakeModelResolver.php`
- Test: `tests/Unit/ListingMakeModelResolverTest.php`

**Interfaces:**
- Consumes: `App\Services\Scrapers\MakeNormalizer` (`canonicalize(string): string`), `CarMake`, `CarModel`.
- Produces: `ListingMakeModelResolver::resolve(?string $title): array` returning `['make' => ?CarMake, 'model' => ?CarModel]`, auto-creating catalog rows.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ListingMakeModelResolverTest.php`:

```php
<?php

use App\Models\CarMake;
use App\Services\ListingMakeModelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function resolver(): ListingMakeModelResolver
{
    return app(ListingMakeModelResolver::class);
}

it('resolves a simple make and model', function (): void {
    CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);

    $result = resolver()->resolve('BMW X5');

    expect($result['make']->name)->toBe('BMW')
        ->and($result['model']->name)->toBe('X5');
});

it('matches a two-word make from the catalog', function (): void {
    CarMake::factory()->create(['name' => 'Alfa Romeo', 'slug' => 'alfa-romeo']);

    $result = resolver()->resolve('Alfa Romeo 159');

    expect($result['make']->name)->toBe('Alfa Romeo')
        ->and($result['model']->name)->toBe('159');
});

it('canonicalizes an alias make to its full name', function (): void {
    $result = resolver()->resolve('VW Passat 2.8');

    expect($result['make']->name)->toBe('Volkswagen')
        ->and($result['model']->name)->toBe('Passat');
});

it('leaves the model null when only a make is present', function (): void {
    CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);

    $result = resolver()->resolve('BMW');

    expect($result['make']->name)->toBe('BMW')
        ->and($result['model'])->toBeNull();
});

it('rejects an engine-spec token as a model', function (): void {
    CarMake::factory()->create(['name' => 'Fiat', 'slug' => 'fiat']);

    $result = resolver()->resolve('Fiat 2.0 JTD');

    expect($result['make']->name)->toBe('Fiat')
        ->and($result['model'])->toBeNull();
});

it('returns null make and model for an empty title', function (): void {
    $result = resolver()->resolve('   ');

    expect($result['make'])->toBeNull()
        ->and($result['model'])->toBeNull();
});

it('auto-creates an unknown make and model, idempotently', function (): void {
    $first = resolver()->resolve('Tesla Model3');
    $second = resolver()->resolve('Tesla Model3');

    expect($first['make']->name)->toBe('Tesla')
        ->and($first['model']->name)->toBe('Model3')
        ->and(CarMake::where('name', 'Tesla')->count())->toBe(1)
        ->and($first['model']->id)->toBe($second['model']->id);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=ListingMakeModelResolverTest`
Expected: FAIL — `Class "App\Services\ListingMakeModelResolver" not found`.

- [ ] **Step 3: Write the resolver**

Create `app/Services/ListingMakeModelResolver.php`:

```php
<?php

namespace App\Services;

use App\Models\CarMake;
use App\Models\CarModel;
use App\Services\Scrapers\MakeNormalizer;
use Illuminate\Support\Str;

class ListingMakeModelResolver
{
    public function __construct(private readonly MakeNormalizer $normalizer) {}

    /**
     * @return array{make: ?CarMake, model: ?CarModel}
     */
    public function resolve(?string $title): array
    {
        $clean = trim(preg_replace('/\s+/u', ' ', (string) $title));

        if ($clean === '') {
            return ['make' => null, 'model' => null];
        }

        $tokens = explode(' ', $clean);

        [$make, $consumed] = $this->matchMake($tokens);

        if ($make === null) {
            return ['make' => null, 'model' => null];
        }

        $model = $this->matchModel($make, array_slice($tokens, $consumed));

        return ['make' => $make, 'model' => $model];
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array{0: ?CarMake, 1: int}  the matched make and the number of leading tokens it consumed
     */
    private function matchMake(array $tokens): array
    {
        for ($n = min(2, count($tokens)); $n >= 1; $n--) {
            $candidate = $this->normalizer->canonicalize(implode(' ', array_slice($tokens, 0, $n)));
            $make = CarMake::whereRaw('LOWER(name) = ?', [mb_strtolower($candidate)])->first();

            if ($make !== null) {
                return [$make, $n];
            }
        }

        $canonical = $this->normalizer->canonicalize($tokens[0]);
        $make = CarMake::firstOrCreate(
            ['name' => $canonical],
            ['slug' => Str::slug($canonical)],
        );

        return [$make, 1];
    }

    /**
     * @param  array<int, string>  $rest
     */
    private function matchModel(CarMake $make, array $rest): ?CarModel
    {
        $token = trim($rest[0] ?? '');

        if ($token === '' || preg_match('/^\d+[.,]\d+/', $token) === 1) {
            return null;
        }

        $existing = $make->models()->whereRaw('LOWER(name) = ?', [mb_strtolower($token)])->first();

        if ($existing !== null) {
            return $existing;
        }

        return $make->models()->create([
            'name' => $token,
            'slug' => Str::slug($token),
        ]);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=ListingMakeModelResolverTest`
Expected: PASS (7 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/ListingMakeModelResolver.php tests/Unit/ListingMakeModelResolverTest.php
git commit -m "feat: ListingMakeModelResolver (catalog-driven, auto-grow)"
```

---

### Task 3: Backfill command

**Files:**
- Create: `app/Console/Commands/ResolveListingMakesModelsCommand.php`
- Test: `tests/Feature/Commands/ResolveListingMakesModelsCommandTest.php`

**Interfaces:**
- Consumes: `ListingMakeModelResolver`, `CarListing`.
- Produces: Artisan command `listings:resolve-makes-models`.

- [ ] **Step 1: Generate the command**

Run: `php artisan make:command ResolveListingMakesModelsCommand --no-interaction`

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Commands/ResolveListingMakesModelsCommandTest.php`:

```php
<?php

use App\Models\CarListing;
use App\Models\CarMake;
use App\Models\CarModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves make and model for existing listings', function (): void {
    CarListing::factory()->create(['title' => 'BMW X5 3.0d', 'car_make_id' => null, 'car_model_id' => null]);
    CarListing::factory()->create(['title' => 'VW Passat 2.8', 'car_make_id' => null, 'car_model_id' => null]);

    $this->artisan('listings:resolve-makes-models')->assertSuccessful();

    $bmw = CarListing::where('title', 'BMW X5 3.0d')->first();
    expect($bmw->make->name)->toBe('BMW')
        ->and($bmw->model->name)->toBe('X5');

    $vw = CarListing::where('title', 'VW Passat 2.8')->first();
    expect($vw->make->name)->toBe('Volkswagen')
        ->and($vw->model->name)->toBe('Passat');
});

it('is idempotent and creates no duplicate catalog rows', function (): void {
    CarListing::factory()->create(['title' => 'BMW X5', 'car_make_id' => null, 'car_model_id' => null]);

    $this->artisan('listings:resolve-makes-models')->assertSuccessful();
    $this->artisan('listings:resolve-makes-models')->assertSuccessful();

    expect(CarMake::where('name', 'BMW')->count())->toBe(1)
        ->and(CarModel::where('name', 'X5')->count())->toBe(1);
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --compact --filter=ResolveListingMakesModelsCommandTest`
Expected: FAIL — command signature is the default `app:resolve-listing-makes-models-command`, so `listings:resolve-makes-models` is not found.

- [ ] **Step 4: Write the command**

Replace `app/Console/Commands/ResolveListingMakesModelsCommand.php` with:

```php
<?php

namespace App\Console\Commands;

use App\Models\CarListing;
use App\Services\ListingMakeModelResolver;
use Illuminate\Console\Command;

class ResolveListingMakesModelsCommand extends Command
{
    protected $signature = 'listings:resolve-makes-models';

    protected $description = 'Resolve and store make/model for every listing from its title';

    public function handle(ListingMakeModelResolver $resolver): int
    {
        $processed = 0;
        $noMake = 0;
        $noModel = 0;

        CarListing::query()->chunkById(200, function ($listings) use ($resolver, &$processed, &$noMake, &$noModel): void {
            foreach ($listings as $listing) {
                $resolved = $resolver->resolve($listing->title);

                $listing->update([
                    'car_make_id' => $resolved['make']?->id,
                    'car_model_id' => $resolved['model']?->id,
                ]);

                $processed++;

                if ($resolved['make'] === null) {
                    $noMake++;
                }

                if ($resolved['model'] === null) {
                    $noModel++;
                }
            }
        });

        $this->info("Processed {$processed} listings; {$noMake} without make, {$noModel} without model.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=ResolveListingMakesModelsCommandTest`
Expected: PASS (2 passed).

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/ResolveListingMakesModelsCommand.php tests/Feature/Commands/ResolveListingMakesModelsCommandTest.php
git commit -m "feat: listings:resolve-makes-models backfill command"
```

---

### Task 4: Resolve make/model at ingestion

**Files:**
- Modify: `app/Jobs/ScrapeListingJob.php` (resolve in `persistRecords`, add to upsert payload)
- Test: `tests/Feature/Jobs/ScrapeListingJobTest.php` (append)

**Interfaces:**
- Consumes: `ListingMakeModelResolver`.
- Produces: each upserted listing carries `car_make_id` / `car_model_id`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Jobs/ScrapeListingJobTest.php`:

```php
it('resolves and stores make and model when persisting a scraped record', function (): void {
    persist([scrapedRecord('mobile.bg', 'www.mobile.bg/obiava-11572426012950088-audi-a4', null)]);

    $listing = CarListing::first();

    expect($listing->make->name)->toBe('Audi')
        ->and($listing->model->name)->toBe('A4')
        ->and(\App\Models\CarMake::where('name', 'Audi')->count())->toBe(1);
});
```

(The shared `scrapedRecord()` helper already sets `'title' => 'Audi A4 AVANT'`.)

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=ScrapeListingJobTest`
Expected: FAIL — `$listing->make` is null (resolution not wired in yet).

- [ ] **Step 3: Wire the resolver into the job**

In `app/Jobs/ScrapeListingJob.php`, add the import:

```php
use App\Services\ListingMakeModelResolver;
```

Inside `persistRecords()`, resolve the make/model for each record before the `upsert` call. Add this immediately before `CarListing::upsert([`:

```php
            $resolved = app(ListingMakeModelResolver::class)->resolve(Arr::get($record, 'title'));
```

Then add these two keys to the `CarListing::upsert([...])` array (e.g. right after the `'title'` line):

```php
                'car_make_id' => $resolved['make']?->id,
                'car_model_id' => $resolved['model']?->id,
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=ScrapeListingJobTest`
Expected: PASS (all job tests, including the new one).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ScrapeListingJob.php tests/Feature/Jobs/ScrapeListingJobTest.php
git commit -m "feat: resolve make/model at listing ingestion"
```

---

### Task 5: Make/model filters in the car-listings component

**Files:**
- Modify: `resources/views/livewire/pages/car-listings.blade.php` (Volt component — PHP logic + dropdown markup)
- Test: `tests/Feature/CarListingsMakeModelFilterTest.php`

**Interfaces:**
- Consumes: `CarMake`, `CarModel`, `CarListing` relationships from Task 1.
- Produces: `make` / `model` URL filters, `makes()` / `models()` computed option lists, make/model-aware query and search.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CarListingsMakeModelFilterTest.php`:

```php
<?php

use App\Models\CarListing;
use App\Models\CarMake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

function seedCatalogListings(): array
{
    $bmw = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    $x5 = $bmw->models()->create(['name' => 'X5', 'slug' => 'x5']);
    $vw = CarMake::factory()->create(['name' => 'Volkswagen', 'slug' => 'volkswagen']);

    CarListing::factory()->create(['title' => 'BMW X5', 'is_active' => true, 'car_make_id' => $bmw->id, 'car_model_id' => $x5->id]);
    CarListing::factory()->create(['title' => 'BMW unspecified model', 'is_active' => true, 'car_make_id' => $bmw->id, 'car_model_id' => null]);
    CarListing::factory()->create(['title' => 'VW Passat', 'is_active' => true, 'car_make_id' => $vw->id, 'car_model_id' => null]);
    CarListing::factory()->create(['title' => 'Mystery car', 'is_active' => true, 'car_make_id' => null, 'car_model_id' => null]);

    return compact('bmw', 'x5', 'vw');
}

it('filters listings by make', function (): void {
    seedCatalogListings();

    Livewire::test('pages.car-listings')
        ->set('make', 'bmw')
        ->assertSee('BMW X5')
        ->assertSee('BMW unspecified model')
        ->assertDontSee('VW Passat');
});

it('surfaces null-make listings under the Unspecified make option', function (): void {
    seedCatalogListings();

    Livewire::test('pages.car-listings')
        ->set('make', 'unspecified')
        ->assertSee('Mystery car')
        ->assertDontSee('BMW X5');
});

it('filters by model and by Unspecified model within a make', function (): void {
    seedCatalogListings();

    Livewire::test('pages.car-listings')
        ->set('make', 'bmw')
        ->set('model', 'x5')
        ->assertSee('BMW X5')
        ->assertDontSee('BMW unspecified model');

    Livewire::test('pages.car-listings')
        ->set('make', 'bmw')
        ->set('model', 'unspecified')
        ->assertSee('BMW unspecified model')
        ->assertDontSee('BMW X5');
});

it('matches the canonical make name in search even when the title uses an alias', function (): void {
    seedCatalogListings();

    Livewire::test('pages.car-listings')
        ->set('search', 'Volkswagen')
        ->assertSee('VW Passat');
});

it('keeps every active listing reachable through some make filter value', function (): void {
    seedCatalogListings();

    $component = Livewire::test('pages.car-listings');
    $makeSlugs = collect($component->get('makes'))->pluck('slug');

    $reachable = collect();
    foreach ($makeSlugs as $slug) {
        $ids = Livewire::test('pages.car-listings')->set('make', $slug)->get('listings')->pluck('id');
        $reachable = $reachable->merge($ids);
    }

    expect($reachable->unique()->sort()->values()->all())
        ->toBe(CarListing::where('is_active', true)->orderBy('id')->pluck('id')->all());
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=CarListingsMakeModelFilterTest`
Expected: FAIL — `make` property does not exist on the component.

- [ ] **Step 3: Add filter state and imports to the Volt PHP block**

In `resources/views/livewire/pages/car-listings.blade.php`, add to the imports at the top (after `use App\Models\CarListing;`):

```php
use App\Models\CarMake;
use App\Models\CarModel;
```

Add these two properties alongside the other `#[Url]` properties (e.g. after the `$location` property at line 39):

```php
    #[Url]
    public string $make = '';

    #[Url]
    public string $model = '';
```

- [ ] **Step 4: Add update hooks**

After `updatedLocation()`, add:

```php
    public function updatedMake(): void
    {
        $this->model = '';
        $this->resetPage();
    }

    public function updatedModel(): void
    {
        $this->resetPage();
    }
```

- [ ] **Step 5: Include make/model in clearFilters and activeFilterCount**

Replace the `clearFilters()` body's `reset([...])` call with one that includes the new keys:

```php
        $this->reset(['search', 'fuelType', 'transmission', 'minPrice', 'maxPrice', 'minYear', 'maxYear', 'location', 'make', 'model']);
```

In `activeFilterCount()`, add before `return $count;`:

```php
        if ($this->make) $count++;
        if ($this->model) $count++;
```

- [ ] **Step 6: Add a selected-make helper and the option computeds**

Add these three methods after the `locations()` computed:

```php
    protected function selectedMake(): ?CarMake
    {
        if ($this->make === '' || $this->make === 'unspecified') {
            return null;
        }

        return CarMake::where('slug', $this->make)->first();
    }

    #[Computed]
    public function makes(): array
    {
        $makes = CarMake::query()
            ->whereHas('listings', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['name', 'slug'])
            ->map(fn (CarMake $m): array => ['slug' => $m->slug, 'name' => $m->name])
            ->toArray();

        if (CarListing::where('is_active', true)->whereNull('car_make_id')->exists()) {
            $makes[] = ['slug' => 'unspecified', 'name' => 'Без марка'];
        }

        return $makes;
    }

    #[Computed]
    public function models(): array
    {
        $make = $this->selectedMake();

        if ($make === null) {
            return [];
        }

        $models = $make->models()
            ->whereHas('listings', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['name', 'slug'])
            ->map(fn (CarModel $m): array => ['slug' => $m->slug, 'name' => $m->name])
            ->toArray();

        $hasUnspecified = CarListing::where('is_active', true)
            ->where('car_make_id', $make->id)
            ->whereNull('car_model_id')
            ->exists();

        if ($hasUnspecified) {
            $models[] = ['slug' => 'unspecified', 'name' => 'Без модел'];
        }

        return $models;
    }
```

- [ ] **Step 7: Apply make/model filters and extend search in `listings()`**

Replace the `listings()` computed method body with:

```php
    #[Computed]
    public function listings(): \Illuminate\Pagination\LengthAwarePaginator|array
    {
        $make = $this->selectedMake();
        $modelId = null;

        if ($make !== null && $this->model !== '' && $this->model !== 'unspecified') {
            $modelId = $make->models()->where('slug', $this->model)->value('id');
        }

        return CarListing::query()
            ->where('is_active', true)
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('title', 'like', '%' . $this->search . '%')
                  ->orWhere('description', 'like', '%' . $this->search . '%')
                  ->orWhereHas('make', fn ($m) => $m->where('name', 'like', '%' . $this->search . '%'))
                  ->orWhereHas('model', fn ($m) => $m->where('name', 'like', '%' . $this->search . '%'));
            }))
            ->when($this->make === 'unspecified', fn ($q) => $q->whereNull('car_make_id'))
            ->when($make, fn ($q) => $q->where('car_make_id', $make->id))
            ->when($make && $this->model === 'unspecified', fn ($q) => $q->whereNull('car_model_id'))
            ->when($modelId, fn ($q) => $q->where('car_model_id', $modelId))
            ->when($this->fuelType, fn ($q) => $q->where('fuel_type', $this->fuelType))
            ->when($this->transmission, fn ($q) => $q->where('transmission', $this->transmission))
            ->when($this->location, fn ($q) => $q->where('location', 'LIKE', "%$this->location%"))
            ->when($this->minPrice, fn ($q) => $q->where('price', '>=', $this->minPrice))
            ->when($this->maxPrice, fn ($q) => $q->where('price', '<=', $this->maxPrice))
            ->when($this->minYear, fn ($q) => $q->where('year', '>=', $this->minYear))
            ->when($this->maxYear, fn ($q) => $q->where('year', '<=', $this->maxYear))
            ->tap(fn ($q) => $this->applySorting($q))
            ->paginate(15);
    }
```

- [ ] **Step 8: Add the dropdowns to the markup**

In the filters sidebar, immediately after the Search `<div>` block (the one ending at line ~306, before `{{-- Fuel Type --}}`), insert:

```blade
                {{-- Make --}}
                <div>
                    <label for="make" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Марка</label>
                    <select
                        wire:model.live="make"
                        id="make"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                    >
                        <option value="">Всички</option>
                        @foreach($this->makes as $makeOption)
                            <option value="{{ $makeOption['slug'] }}">{{ $makeOption['name'] }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Model --}}
                <div>
                    <label for="model" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Модел</label>
                    <select
                        wire:model.live="model"
                        id="model"
                        @disabled($this->make === '')
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                    >
                        <option value="">Всички</option>
                        @foreach($this->models as $modelOption)
                            <option value="{{ $modelOption['slug'] }}">{{ $modelOption['name'] }}</option>
                        @endforeach
                    </select>
                </div>
```

- [ ] **Step 9: Add make/model to the wire:loading and wire:target lists**

In the three places that list filter targets (results header `wire:loading.remove` / `wire:loading`, and the loading overlay `wire:loading.delay`), add `make, model` to each `wire:target="..."` attribute. For example the results header becomes:

```blade
                    <span wire:loading.remove wire:target="search, make, model, fuelType, transmission, location, minPrice, maxPrice, minYear, maxYear, sortBy, sortDirection">
                        Намерени <strong>{{ $this->listings->total() }}</strong> обяви
                    </span>
                    <span wire:loading wire:target="search, make, model, fuelType, transmission, location, minPrice, maxPrice, minYear, maxYear, sortBy, sortDirection">
                        Зареждане...
                    </span>
```

And the overlay:

```blade
            <div wire:loading.delay wire:target="search, make, model, fuelType, transmission, location, minPrice, maxPrice, minYear, maxYear, sortBy, sortDirection, gotoPage, previousPage, nextPage" class="fixed inset-0 z-50 flex items-center justify-center bg-black/20">
```

- [ ] **Step 10: Run the test to verify it passes**

Run: `php artisan test --compact --filter=CarListingsMakeModelFilterTest`
Expected: PASS (5 passed).

- [ ] **Step 11: Run the full suite and Pint**

Run:
```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
```
Expected: all tests pass; Pint formats the touched files.

- [ ] **Step 12: Commit**

```bash
git add resources/views/livewire/pages/car-listings.blade.php tests/Feature/CarListingsMakeModelFilterTest.php
git commit -m "feat: make/model filters and make/model-aware search"
```

---

## Notes for the implementer

- **Run order for the real data:** after all tasks pass, run `php artisan listings:resolve-makes-models` once to backfill every existing listing. New/updated listings are resolved automatically by `ScrapeListingJob`.
- **Coverage guarantee:** the final test (`keeps every active listing reachable through some make filter value`) is the executable proof of the "none left out" requirement — it asserts the union of all make-filter results equals all active listings.
- **`@disabled` Blade directive** disables the model select until a make is chosen; the option list is empty until then anyway.
