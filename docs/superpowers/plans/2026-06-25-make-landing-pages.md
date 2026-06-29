# Make Landing Pages (SEO) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship one SEO-optimized, indexable landing page per car make at `/obiavi/{make-slug}` showing that make's listings, plus a `/marki` hub and sitemap coverage.

**Architecture:** Two new single-file Livewire page components (`pages.make-listings`, `pages.make-index`) following the existing `pages.car-listings` Volt-style pattern. The listing card is extracted into a shared `<x-car-listing-card>` anonymous component, and its two helper methods are consolidated onto the `CarListing` model (today they are duplicated in both page components). SEO is delivered via the existing `@push('seo')` / `@stack('seo')` convention plus JSON-LD.

**Tech Stack:** Laravel 12, Livewire 4 (Volt single-file components), Tailwind v4, Pest 4, PostgreSQL.

## Global Constraints

- App is **Bulgarian-only**. All user-facing copy is in Bulgarian. (See staging-env-drift memory.)
- Layout wraps the title as `{{ $title }} - AutoSearch`; component titles supply only the left half.
- Sort by recency uses `COALESCE(published_at, created_at)` — never bare `published_at` (NULLs would bury dated rows). (See published-at-no-backfill memory.)
- Make slugs are **latin** (`bmw`, `mercedes-benz`) — the existing `car_makes.slug` values.
- Use Eloquent + relationships, eager-load to avoid N+1. Prefer `Model::query()` over `DB::`.
- Run `vendor/bin/pint --dirty --format agent` before each commit.
- Tests are Pest, `uses(RefreshDatabase::class)` and `$this->withoutVite()` in `beforeEach`.
- Only makes with **active** listings get a page / sitemap entry; otherwise 404.

---

### Task 1: Unique index on `car_makes.slug`

The slug becomes a routing key, so it must be unique. Today only `name` is unique.

**Files:**
- Create: `database/migrations/2026_06_25_000001_add_unique_index_to_car_makes_slug.php`
- Test: `tests/Feature/Models/CarMakeSlugTest.php`

**Interfaces:**
- Produces: a unique constraint on `car_makes.slug`.

- [ ] **Step 1: Pre-flight — confirm no duplicate slugs exist**

Run:
```bash
php artisan tinker --execute="echo App\Models\CarMake::query()->select('slug')->groupBy('slug')->havingRaw('count(*) > 1')->count();"
```
Expected: `0`. If non-zero, STOP and resolve duplicates with the user before adding the constraint.

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Models\CarMake;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forbids two makes sharing a slug', function (): void {
    CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);

    expect(fn () => CarMake::factory()->create(['name' => 'BMW Group', 'slug' => 'bmw']))
        ->toThrow(QueryException::class);
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact --filter=CarMakeSlugTest`
Expected: FAIL (no unique constraint yet — second insert succeeds).

- [ ] **Step 4: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_makes', function (Blueprint $table): void {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('car_makes', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
        });
    }
};
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan migrate && php artisan test --compact --filter=CarMakeSlugTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations tests/Feature/Models/CarMakeSlugTest.php
git commit -m "feat: enforce unique car_makes.slug for routing"
```

---

### Task 2: Consolidate listing helpers onto `CarListing`

`formatImageUrl()` and `getSource()` are duplicated verbatim in both `pages.car-listings` and `pages.car-detail`. Move them to the model so the new shared card (Task 3) and both pages use one copy.

**Files:**
- Modify: `app/Models/CarListing.php`
- Modify: `resources/views/livewire/pages/car-listings.blade.php` (remove its 2 methods; rewire usages)
- Modify: `resources/views/livewire/pages/car-detail.blade.php` (remove its 2 methods; rewire usages)
- Test: `tests/Unit/CarListingHelpersTest.php`

**Interfaces:**
- Produces:
  - `CarListing::displayImageUrl(): ?string` — returns `image_url`, prefixed with `https://` if it lacks the scheme; `null` stays `null`.
  - `CarListing::sourceLabel(string $url): ?string` — maps a source URL to `cars.bg` / `car24.bg` / `mobile.bg` / `auto.bg`, else `null`.

- [ ] **Step 1: Write the failing unit test**

```php
<?php

use App\Models\CarListing;

it('normalizes the display image url', function (): void {
    expect((new CarListing(['image_url' => 'cdn.x/a.webp']))->displayImageUrl())
        ->toBe('https://cdn.x/a.webp');
    expect((new CarListing(['image_url' => 'https://cdn.x/a.webp']))->displayImageUrl())
        ->toBe('https://cdn.x/a.webp');
    expect((new CarListing(['image_url' => null]))->displayImageUrl())->toBeNull();
});

it('labels a source url by host', function (): void {
    $car = new CarListing();
    expect($car->sourceLabel('https://www.cars.bg/offer/1'))->toBe('cars.bg');
    expect($car->sourceLabel('https://www.mobile.bg/obiava/1'))->toBe('mobile.bg');
    expect($car->sourceLabel('https://www.car24.bg/x'))->toBe('car24.bg');
    expect($car->sourceLabel('https://www.auto.bg/x'))->toBe('auto.bg');
    expect($car->sourceLabel('https://example.com/x'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CarListingHelpersTest`
Expected: FAIL with "Call to undefined method ... displayImageUrl()".

- [ ] **Step 3: Add the methods to `CarListing`**

Add inside the `CarListing` class (e.g. after `sourceDate()`):

```php
public function displayImageUrl(): ?string
{
    if ($this->image_url === null || $this->image_url === '') {
        return null;
    }

    return str_starts_with($this->image_url, 'https://')
        ? $this->image_url
        : 'https://'.$this->image_url;
}

public function sourceLabel(string $url): ?string
{
    return match (true) {
        Str::contains($url, 'cars.bg') => 'cars.bg',
        Str::contains($url, 'car24.bg') => 'car24.bg',
        Str::contains($url, 'mobile.bg') => 'mobile.bg',
        Str::contains($url, 'auto.bg') => 'auto.bg',
        default => null,
    };
}
```
(`Str` is already imported in this model.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=CarListingHelpersTest`
Expected: PASS.

- [ ] **Step 5: Rewire `car-detail.blade.php`**

Delete its `public function getSource($url)` (line ~94) and `public function formatImageUrl($url)` (line ~107) methods. Then replace usages:
- `$this->formatImageUrl($car->image_url)` → `$car->displayImageUrl()` (line ~80, inside the JSON-LD builder)
- `$this->formatImageUrl($carListing->image_url)` → `$carListing->displayImageUrl()` (lines ~130, ~131, ~199)
- `$this->getSource($url)` → `$carListing->sourceLabel($url)` (line ~288)

- [ ] **Step 6: Rewire `car-listings.blade.php`**

Delete its `public function getSource($url)` and `public function formatImageUrl($url)` methods (near the end of the `@php` class block, before `?>`). Usages in the card are removed in Task 3, but to keep the page green between tasks, replace the two in-loop usages now:
- `$this->formatImageUrl($car->image_url)` → `$car->displayImageUrl()`
- `$this->getSource($url)` → `$car->sourceLabel($url)`

- [ ] **Step 7: Run the full suite (regression on both pages)**

Run: `php artisan test --compact`
Expected: PASS (existing `CarDetailSeoTest`, `ListingsSeoTest`, etc. still green).

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/CarListing.php resources/views/livewire/pages tests/Unit/CarListingHelpersTest.php
git commit -m "refactor: move listing image/source helpers onto CarListing model"
```

---

### Task 3: Extract shared `<x-car-listing-card>` component

**Files:**
- Create: `resources/views/components/car-listing-card.blade.php`
- Modify: `resources/views/livewire/pages/car-listings.blade.php` (use the component in the loop)
- Test: `tests/Feature/CarListingCardTest.php`

**Interfaces:**
- Consumes: `CarListing::displayImageUrl()`, `CarListing::sourceLabel()` (Task 2).
- Produces: `<x-car-listing-card :listing="$car" />` — renders one listing card; forwards extra HTML attributes (e.g. `wire:key`) onto the root `<a>`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\CarListing;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

it('renders a listing card with title, price and detail link', function (): void {
    $car = CarListing::factory()->create([
        'title' => 'BMW X5 Test',
        'price' => 25000,
    ]);

    get(route('car-listings'))
        ->assertOk()
        ->assertSee('BMW X5 Test')
        ->assertSee(url('/cars/'.$car->id), false);
});
```

- [ ] **Step 2: Run test to verify it fails or passes**

Run: `php artisan test --compact --filter=CarListingCardTest`
Expected: PASS already (card still inline). This is the regression guard we must keep green through the extraction — proceed to extract, then re-run.

- [ ] **Step 3: Create the component**

Create `resources/views/components/car-listing-card.blade.php`. Move the entire `<a ...> ... </a>` card markup currently in `car-listings.blade.php` (the block inside `@foreach($this->listings as $car)`, roughly lines 599–722) into this file **verbatim**, with these exact substitutions:
- Add `@props(['listing'])` as the first line.
- Replace every `$car->` with `$listing->`.
- Replace `$car->displayImageUrl()` references already use `$listing->displayImageUrl()` after the rename; ensure the `<img src>` uses `{{ $listing->displayImageUrl() }}`.
- Replace `$car->sourceLabel($url)` → `{{ $listing->sourceLabel($url) }}`.
- On the root `<a>` tag, remove the hard-coded `wire:key="car-{{ $car->id }}"` and instead append `{{ $attributes }}` so callers pass `wire:key`. Keep `href="{{ url('/cars/'.$listing->id) }}"`, `wire:navigate`, and the existing class list.

Root tag becomes:
```blade
@props(['listing'])

<a
    href="{{ url('/cars/'.$listing->id) }}"
    wire:navigate
    {{ $attributes->merge(['class' => 'group flex flex-col overflow-hidden rounded-lg bg-white shadow-sm transition-shadow hover:shadow-md dark:bg-gray-800']) }}
>
    {{-- ...rest of the moved markup, using $listing... --}}
</a>
```

- [ ] **Step 4: Use the component in `car-listings.blade.php`**

Replace the moved `<a>...</a>` block inside the loop with:
```blade
@foreach($this->listings as $car)
    <x-car-listing-card :listing="$car" wire:key="car-{{ $car->id }}" />
@endforeach
```

- [ ] **Step 5: Run tests + manual sanity**

Run: `php artisan test --compact --filter=CarListingCardTest`
Expected: PASS.
Then: `php artisan test --compact` — Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/car-listing-card.blade.php resources/views/livewire/pages/car-listings.blade.php tests/Feature/CarListingCardTest.php
git commit -m "refactor: extract shared x-car-listing-card component"
```

---

### Task 4: `pages.make-listings` page — routing, 404 rules, listings

**Files:**
- Create: `resources/views/livewire/pages/make-listings.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/MakeListingsTest.php`

**Interfaces:**
- Consumes: `<x-car-listing-card>` (Task 3); `CarMake`, `CarModel`, `CarListing` models.
- Produces: route `make-listings` at `/obiavi/{make}`; the page resolves a `CarMake` by slug and 404s when the slug is unknown or has zero active listings.

- [ ] **Step 1: Add the routes**

In `routes/web.php`, after the `car-detail` line and before the sitemap routes, register **both** the hub and the make route now (the hub view is built in Task 6, but registering its route here lets Task 5's `route('make-index')` calls resolve):
```php
Route::livewire('/marki', 'pages.make-index')->name('make-index');
Route::livewire('/obiavi/{make}', 'pages.make-listings')->name('make-listings');
```

- [ ] **Step 2: Write the failing tests**

```php
<?php

use App\Models\CarListing;
use App\Models\CarMake;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

it('shows a make page listing only that make’s active cars', function (): void {
    $bmw = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    $audi = CarMake::factory()->create(['name' => 'Audi', 'slug' => 'audi']);

    $mine = CarListing::factory()->create(['car_make_id' => $bmw->id, 'title' => 'BMW X5 Keep']);
    $other = CarListing::factory()->create(['car_make_id' => $audi->id, 'title' => 'Audi A4 Hide']);

    get(route('make-listings', 'bmw'))
        ->assertOk()
        ->assertSee('BMW X5 Keep')
        ->assertDontSee('Audi A4 Hide');
});

it('404s for an unknown make slug', function (): void {
    get(route('make-listings', 'does-not-exist'))->assertNotFound();
});

it('404s for a make with no active listings', function (): void {
    $bmw = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    CarListing::factory()->inactive()->create(['car_make_id' => $bmw->id]);

    get(route('make-listings', 'bmw'))->assertNotFound();
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact --filter=MakeListingsTest`
Expected: FAIL (component view does not exist yet).

- [ ] **Step 4: Create the component (logic + minimal view)**

```blade
<?php

use App\Models\CarListing;
use App\Models\CarMake;
use App\Models\CarModel;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.app')]
class extends Component {
    use WithPagination;

    public CarMake $make;

    public function mount(string $make): void
    {
        $resolved = CarMake::where('slug', $make)->firstOrFail();

        abort_unless(
            CarListing::query()->where('is_active', true)->where('car_make_id', $resolved->id)->exists(),
            404
        );

        $this->make = $resolved;
    }

    public function render(): View
    {
        return $this->view()->title($this->make->name.' автомобили');
    }

    #[Computed]
    public function listings(): LengthAwarePaginator
    {
        return CarListing::query()
            ->where('is_active', true)
            ->where('car_make_id', $this->make->id)
            ->orderByRaw('COALESCE(published_at, created_at) desc')
            ->paginate(15);
    }

    /**
     * @return array{count: int, minPrice: int, maxPrice: int}
     */
    #[Computed]
    public function stats(): array
    {
        $row = CarListing::query()
            ->where('is_active', true)
            ->where('car_make_id', $this->make->id)
            ->selectRaw('count(*) as count, min(price) as min_price, max(price) as max_price')
            ->first();

        return [
            'count' => (int) $row->count,
            'minPrice' => (int) $row->min_price,
            'maxPrice' => (int) $row->max_price,
        ];
    }

    /**
     * @return array<int, array{name: string, slug: string, count: int}>
     */
    #[Computed]
    public function topModels(): array
    {
        return $this->make->models()
            ->withCount(['listings as count' => fn ($q) => $q->where('is_active', true)])
            ->whereHas('listings', fn ($q) => $q->where('is_active', true))
            ->orderByDesc('count')
            ->limit(12)
            ->get(['id', 'name', 'slug'])
            ->map(fn (CarModel $m): array => ['name' => $m->name, 'slug' => $m->slug, 'count' => $m->count])
            ->toArray();
    }
}
?>

<div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <h1 class="mb-6 text-2xl font-bold text-gray-900 sm:text-3xl dark:text-white">
        {{ $make->name }} обяви
    </h1>

    @if($this->listings->count() > 0)
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 py-5">
            @foreach($this->listings as $car)
                <x-car-listing-card :listing="$car" wire:key="car-{{ $car->id }}" />
            @endforeach
        </div>

        <div>{{ $this->listings->links() }}</div>
    @else
        <p class="text-gray-600 dark:text-gray-300">Няма намерени обяви.</p>
    @endif
</div>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=MakeListingsTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/livewire/pages/make-listings.blade.php tests/Feature/MakeListingsTest.php
git commit -m "feat: add /obiavi/{make} landing page"
```

---

### Task 5: Make-page SEO head, intro copy, and JSON-LD

Adds unique title (Task 4 set it), meta description, self-canonical, a data-driven intro paragraph (anti-thin-content), top-model internal links, breadcrumb nav, and `BreadcrumbList` + `CollectionPage` JSON-LD. Uses marketing-skills:schema for structured data and tailwindcss-development for layout.

**Files:**
- Modify: `resources/views/livewire/pages/make-listings.blade.php`
- Test: `tests/Feature/MakeListingsSeoTest.php`

**Interfaces:**
- Consumes: `$this->stats`, `$this->topModels`, `$this->make` (Task 4).
- Produces: SEO `@push('seo')` block + visible intro/model-links/breadcrumb on the make page.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\CarListing;
use App\Models\CarMake;
use App\Models\CarModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

it('renders SEO head for a make page', function (): void {
    $bmw = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    CarListing::factory()->count(3)->create(['car_make_id' => $bmw->id]);

    $response = get(route('make-listings', 'bmw'))->assertOk();

    $response
        ->assertSee('<title>BMW автомобили - AutoSearch</title>', false)
        ->assertSee('<meta name="description"', false)
        ->assertSee('<link rel="canonical" href="'.route('make-listings', 'bmw').'">', false)
        ->assertSee('"@type":"BreadcrumbList"', false)
        ->assertSee('"@type":"CollectionPage"', false);

    expect(substr_count($response->getContent(), '<h1'))->toBe(1);
});

it('links to the make’s top models', function (): void {
    $bmw = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    $x5 = CarModel::factory()->create(['car_make_id' => $bmw->id, 'name' => 'X5', 'slug' => 'x5']);
    CarListing::factory()->create(['car_make_id' => $bmw->id, 'car_model_id' => $x5->id]);

    get(route('make-listings', 'bmw'))
        ->assertOk()
        ->assertSee(route('car-listings', ['make' => 'bmw', 'model' => 'x5']), false);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=MakeListingsSeoTest`
Expected: FAIL (no meta/canonical/JSON-LD/model links yet).

- [ ] **Step 3: Add a meta-description method to the component**

Inside the component class, add:
```php
public function metaDescription(): string
{
    $stats = $this->stats;

    return sprintf(
        'Разгледай %d обяви за %s от mobile.bg, cars.bg, auto.bg и car24.bg. Цени от %s до %s EUR.',
        $stats['count'],
        $this->make->name,
        number_format($stats['minPrice'], 0, ',', ' '),
        number_format($stats['maxPrice'], 0, ',', ' '),
    );
}
```

- [ ] **Step 4: Add the SEO + content markup to the view**

Immediately inside the root `<div>`, before the `<h1>`, add the SEO stack and breadcrumb. Replace the existing `<h1>` block with the heading + intro + model links:

```blade
@push('seo')
    <link rel="canonical" href="{{ route('make-listings', $make->slug) }}">
    <meta name="description" content="{{ $this->metaDescription() }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $make->name }} автомобили - AutoSearch">
    <meta property="og:description" content="{{ $this->metaDescription() }}">
    <meta property="og:url" content="{{ route('make-listings', $make->slug) }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $make->name }} автомобили - AutoSearch">
    <meta name="twitter:description" content="{{ $this->metaDescription() }}">

    <script type="application/ld+json">
        @json([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Начало', 'item' => route('car-listings')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Марки', 'item' => route('make-index')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $make->name, 'item' => route('make-listings', $make->slug)],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    </script>
    <script type="application/ld+json">
        @json([
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $make->name.' автомобили',
            'description' => $this->metaDescription(),
            'url' => route('make-listings', $make->slug),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    </script>
@endpush

<nav class="mb-4 text-sm text-gray-500 dark:text-gray-400" aria-label="breadcrumb">
    <a href="{{ route('car-listings') }}" class="hover:text-blue-600" wire:navigate>Начало</a>
    <span class="mx-1">/</span>
    <a href="{{ route('make-index') }}" class="hover:text-blue-600" wire:navigate>Марки</a>
    <span class="mx-1">/</span>
    <span class="text-gray-700 dark:text-gray-300">{{ $make->name }}</span>
</nav>

<h1 class="mb-2 text-2xl font-bold text-gray-900 sm:text-3xl dark:text-white">
    {{ $make->name }} обяви
</h1>

<p class="mb-6 max-w-3xl text-gray-600 dark:text-gray-300">
    {{ $this->metaDescription() }}
</p>

@if(count($this->topModels) > 0)
    <div class="mb-6 flex flex-wrap gap-2">
        @foreach($this->topModels as $model)
            <a
                href="{{ route('car-listings', ['make' => $make->slug, 'model' => $model['slug']]) }}"
                wire:navigate
                class="rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-700 hover:bg-blue-100 hover:text-blue-700 dark:bg-gray-700 dark:text-gray-200"
            >
                {{ $model['name'] }} <span class="text-gray-400">({{ $model['count'] }})</span>
            </a>
        @endforeach
    </div>
@endif
```
Remove the now-duplicated `<h1>` that Task 4 added (this block replaces it).

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=MakeListingsSeoTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/pages/make-listings.blade.php tests/Feature/MakeListingsSeoTest.php
git commit -m "feat: SEO head, intro copy and JSON-LD for make pages"
```

---

### Task 6: `/marki` hub page + footer link

**Files:**
- Create: `resources/views/livewire/pages/make-index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/components/layouts/app.blade.php` (footer link)
- Test: `tests/Feature/MakeIndexTest.php`

**Interfaces:**
- Consumes: `CarMake`, `CarListing`.
- Produces: route `make-index` at `/marki`; lists makes with active listings, each linking to `make-listings`.

- [ ] **Step 1: Confirm the route exists**

The `make-index` route was already registered in Task 4. Verify it is present in `routes/web.php`:
```php
Route::livewire('/marki', 'pages.make-index')->name('make-index');
```
If missing, add it. This task only builds the hub view, the footer link, and the test.

- [ ] **Step 2: Write the failing tests**

```php
<?php

use App\Models\CarListing;
use App\Models\CarMake;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

it('lists makes that have active listings and links to their pages', function (): void {
    $bmw = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    $empty = CarMake::factory()->create(['name' => 'Empty', 'slug' => 'empty']);
    CarListing::factory()->create(['car_make_id' => $bmw->id]);

    get(route('make-index'))
        ->assertOk()
        ->assertSee('BMW')
        ->assertSee(route('make-listings', 'bmw'), false)
        ->assertDontSee(route('make-listings', 'empty'), false);
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact --filter=MakeIndexTest`
Expected: FAIL (view missing).

- [ ] **Step 4: Create the hub component**

```blade
<?php

use App\Models\CarListing;
use App\Models\CarMake;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.layouts.app')]
class extends Component {
    public function render(): View
    {
        return $this->view()->title('Марки автомобили');
    }

    /**
     * @return array<int, array{name: string, slug: string, count: int}>
     */
    #[Computed]
    public function makes(): array
    {
        $counts = CarListing::query()
            ->where('is_active', true)
            ->whereNotNull('car_make_id')
            ->selectRaw('car_make_id, count(*) as total')
            ->groupBy('car_make_id')
            ->pluck('total', 'car_make_id');

        return CarMake::whereIn('id', $counts->keys())
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (CarMake $m): array => ['name' => $m->name, 'slug' => $m->slug, 'count' => $counts[$m->id]])
            ->toArray();
    }
}
?>

<div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    @push('seo')
        <link rel="canonical" href="{{ route('make-index') }}">
        <meta name="description" content="Разгледай всички марки автомобили с активни обяви в AutoSearch.">
    @endpush

    <h1 class="mb-6 text-2xl font-bold text-gray-900 sm:text-3xl dark:text-white">
        Марки автомобили
    </h1>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @foreach($this->makes as $make)
            <a
                href="{{ route('make-listings', $make['slug']) }}"
                wire:key="make-{{ $make['slug'] }}"
                wire:navigate
                class="flex items-center justify-between rounded-lg bg-white px-4 py-3 shadow-sm hover:shadow-md dark:bg-gray-800"
            >
                <span class="font-medium text-gray-900 dark:text-white">{{ $make['name'] }}</span>
                <span class="text-sm text-gray-400">{{ $make['count'] }}</span>
            </a>
        @endforeach
    </div>
</div>
```

- [ ] **Step 5: Add the footer link**

In `resources/views/components/layouts/app.blade.php`, inside the footer's link row (the `<p>AutoSearch ...` sibling area), add a link to the hub. Add before the source links `<div>`:
```blade
<a href="{{ route('make-index') }}" class="text-sm text-gray-500 hover:text-blue-600 dark:text-white" wire:navigate>Марки</a>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter=MakeIndexTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/livewire/pages/make-index.blade.php resources/views/components/layouts/app.blade.php tests/Feature/MakeIndexTest.php
git commit -m "feat: add /marki hub page and footer link"
```

---

### Task 7: Makes sitemap

Add `sitemap-makes.xml` and register it in the sitemap index.

**Files:**
- Modify: `app/Http/Controllers/SeoController.php`
- Modify: `routes/web.php`
- Create: `resources/views/seo/sitemap-makes.blade.php`
- Modify: `resources/views/seo/sitemap-index.blade.php`
- Test: `tests/Feature/MakeSitemapTest.php`

**Interfaces:**
- Consumes: `CarMake`, `CarListing`, `SitemapCache`.
- Produces: route `sitemap.makes` at `/sitemap-makes.xml`; the sitemap index references it.

- [ ] **Step 1: Add the route**

In `routes/web.php`, next to the other sitemap routes:
```php
Route::get('/sitemap-makes.xml', [SeoController::class, 'sitemapMakes'])->name('sitemap.makes');
```
> Note: this literal route must be registered **before** `/sitemap-{page}.xml` is matched — `whereNumber('page')` already prevents `makes` from matching the numeric route, so ordering is safe either way.

- [ ] **Step 2: Write the failing tests**

```php
<?php

use App\Models\CarListing;
use App\Models\CarMake;
use App\Services\SitemapCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Cache::flush();
});

it('lists make pages in the makes sitemap, excluding makes without active listings', function (): void {
    $bmw = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    $empty = CarMake::factory()->create(['name' => 'Empty', 'slug' => 'empty']);
    CarListing::factory()->create(['car_make_id' => $bmw->id]);

    get(route('sitemap.makes'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee('<urlset', false)
        ->assertSee(route('make-listings', 'bmw'), false)
        ->assertDontSee(route('make-listings', 'empty'), false);
});

it('references the makes sitemap from the index', function (): void {
    get('/sitemap.xml')
        ->assertOk()
        ->assertSee(route('sitemap.makes'), false);
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact --filter=MakeSitemapTest`
Expected: FAIL (method/route/view missing).

- [ ] **Step 4: Add the controller method**

In `SeoController`, add:
```php
public function sitemapMakes(): Response
{
    $xml = SitemapCache::remember('makes', function (): string {
        $makeIds = CarListing::query()
            ->where('is_active', true)
            ->whereNotNull('car_make_id')
            ->distinct()
            ->pluck('car_make_id');

        $makes = CarMake::whereIn('id', $makeIds)
            ->orderBy('name')
            ->get(['slug']);

        return view('seo.sitemap-makes', ['makes' => $makes])->render();
    });

    return response($xml)->header('Content-Type', 'application/xml');
}
```
Add `use App\Models\CarMake;` to the controller's imports.

- [ ] **Step 5: Create the makes sitemap view**

`resources/views/seo/sitemap-makes.blade.php`:
```blade
<?php /** @var \Illuminate\Support\Collection<int, \App\Models\CarMake> $makes */ ?>
<?php echo '<?xml version="1.0" encoding="UTF-8"?>'."\n"; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    @foreach ($makes as $make)
        <url>
            <loc>{{ route('make-listings', $make->slug) }}</loc>
            <changefreq>daily</changefreq>
            <priority>0.7</priority>
        </url>
    @endforeach
</urlset>
```

- [ ] **Step 6: Reference it from the index**

In `resources/views/seo/sitemap-index.blade.php`, add a `<sitemap>` entry for the makes sitemap (inside `<sitemapindex>`, before the `@for` loop):
```blade
<sitemap>
    <loc>{{ route('sitemap.makes') }}</loc>
</sitemap>
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --compact --filter=MakeSitemapTest`
Expected: PASS.

- [ ] **Step 8: Final full-suite run + Pint**

Run: `vendor/bin/pint --dirty --format agent && php artisan test --compact`
Expected: all PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/SeoController.php routes/web.php resources/views/seo tests/Feature/MakeSitemapTest.php
git commit -m "feat: add makes sitemap and index reference"
```

---

## Notes for the executor

- **Frontend changes won't show** until assets build. After Task 3/6, if visually verifying, run `npm run build` (or ask the user to run `npm run dev`).
- The `SitemapCache` caches per key — tests flush the cache in `beforeEach`. If you change a sitemap and don't see it, the cache is the cause.
- Keep each make page to exactly one `<h1>` (SEO).
