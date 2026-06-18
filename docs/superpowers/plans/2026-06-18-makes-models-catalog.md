# Makes/Models Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a one-time `scrape:makes-models` Artisan command that scrapes car makes from all four platforms, deduplicates them into canonical full names, scrapes models from auto.bg, and stores everything in two new tables.

**Architecture:** Two normalized tables (`car_makes` → `car_models`). Each scraper gains a `scrapeMakes()` method; auto.bg additionally gains `scrapeModels()`. A `MakeNormalizer` service handles trim/collapse/alias deduplication. The command orchestrates: pool makes → dedupe → persist makes → crawl auto.bg models per make → persist models. All persistence uses `firstOrCreate` for idempotency.

**Tech Stack:** Laravel 12, PHP 8.3, Symfony DomCrawler, Laravel HTTP client, Pest 4.

## Global Constraints

- PHP 8.3; explicit return types on every method; constructor property promotion where constructors exist.
- Curly braces on all control structures, even single-line bodies.
- Use `php artisan make:` generators for new files; pass `--no-interaction`.
- Eloquent relationships with return type hints; no raw `DB::`.
- Models use `casts()` method (not `$casts` property) if casts are needed; `protected $guarded = [];` per existing `CarListing`.
- New models get factories.
- Tests are Pest; most are feature tests. Create with `php artisan make:test --pest {name}`.
- Run `vendor/bin/pint --dirty --format agent` before finalizing.
- HTTP requests in scrapers reuse the existing `browserHeaders()` helper from `App\Services\Scrapers\Scraper`.
- `scrapeMakes()` returns a uniform shape across all scrapers: `array<int, array{name: string, slug: string|null}>`.

---

### Task 1: Tables, models, and factories

**Files:**
- Create: `database/migrations/2026_06_18_000001_create_car_makes_table.php`
- Create: `database/migrations/2026_06_18_000002_create_car_models_table.php`
- Create: `app/Models/CarMake.php`
- Create: `app/Models/CarModel.php`
- Create: `database/factories/CarMakeFactory.php`
- Create: `database/factories/CarModelFactory.php`
- Test: `tests/Feature/Models/CarMakeModelTest.php`

**Interfaces:**
- Produces:
  - `App\Models\CarMake` — columns `id, name (unique), slug, timestamps`; `models(): HasMany` → `CarModel`.
  - `App\Models\CarModel` — columns `id, car_make_id (FK), name, slug, timestamps`; unique `(car_make_id, name)`; `make(): BelongsTo` → `CarMake`.
  - `CarMakeFactory`, `CarModelFactory` (the latter creates an associated `CarMake`).

- [ ] **Step 1: Generate the migrations and models**

Run:
```bash
php artisan make:model CarMake -m --no-interaction
php artisan make:model CarModel -m --no-interaction
php artisan make:factory CarMakeFactory --no-interaction
php artisan make:factory CarModelFactory --no-interaction
```
Then rename the generated migration files to the exact names listed under **Files** so ordering is deterministic (makes before models).

- [ ] **Step 2: Write the `car_makes` migration**

In `database/migrations/2026_06_18_000001_create_car_makes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_makes', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_makes');
    }
};
```

- [ ] **Step 3: Write the `car_models` migration**

In `database/migrations/2026_06_18_000002_create_car_models_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('car_make_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
            $table->unique(['car_make_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_models');
    }
};
```

- [ ] **Step 4: Write the `CarMake` model**

In `app/Models/CarMake.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CarMakeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarMake extends Model
{
    /** @use HasFactory<CarMakeFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return HasMany<CarModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(CarModel::class);
    }
}
```

- [ ] **Step 5: Write the `CarModel` model**

In `app/Models/CarModel.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CarModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarModel extends Model
{
    /** @use HasFactory<CarModelFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return BelongsTo<CarMake, $this>
     */
    public function make(): BelongsTo
    {
        return $this->belongsTo(CarMake::class);
    }
}
```

- [ ] **Step 6: Write the factories**

In `database/factories/CarMakeFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\CarMake;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CarMake>
 */
class CarMakeFactory extends Factory
{
    protected $model = CarMake::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->word());

        return [
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}
```

In `database/factories/CarModelFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\CarMake;
use App\Models\CarModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CarModel>
 */
class CarModelFactory extends Factory
{
    protected $model = CarModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->word());

        return [
            'car_make_id' => CarMake::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}
```

- [ ] **Step 7: Write the failing test**

In `tests/Feature/Models/CarMakeModelTest.php`:

```php
<?php

use App\Models\CarMake;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('relates a make to its models', function (): void {
    $make = CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    $make->models()->create(['name' => '320', 'slug' => '320']);

    expect($make->models)->toHaveCount(1)
        ->and($make->models->first()->make->name)->toBe('BMW');
});

it('enforces a unique make name', function (): void {
    CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);

    expect(fn (): CarMake => CarMake::factory()->create(['name' => 'BMW', 'slug' => 'bmw']))
        ->toThrow(QueryException::class);
});

it('enforces a unique model name within a make', function (): void {
    $make = CarMake::factory()->create();
    $make->models()->create(['name' => '320', 'slug' => '320']);

    expect(fn () => $make->models()->create(['name' => '320', 'slug' => '320']))
        ->toThrow(QueryException::class);
});
```

- [ ] **Step 8: Run the test**

Run: `php artisan test --compact --filter=CarMakeModelTest`
Expected: PASS (3 passed).

- [ ] **Step 9: Commit**

```bash
git add database/migrations app/Models/CarMake.php app/Models/CarModel.php database/factories/CarMakeFactory.php database/factories/CarModelFactory.php tests/Feature/Models/CarMakeModelTest.php
git commit -m "feat: car_makes and car_models tables, models, factories"
```

---

### Task 2: MakeNormalizer service

**Files:**
- Create: `app/Services/Scrapers/MakeNormalizer.php`
- Test: `tests/Unit/MakeNormalizerTest.php`

**Interfaces:**
- Produces:
  - `MakeNormalizer::canonicalize(string $name): string` — trims, collapses whitespace, applies the alias map (keyed on the lowercased cleaned name), returns the canonical full name.
  - `MakeNormalizer::dedupe(array $names): array` — `@param array<int, string> $names` → `@return array<int, string>` canonical names, deduplicated case-insensitively, first-occurrence order preserved, empties dropped.

- [ ] **Step 1: Write the failing test**

In `tests/Unit/MakeNormalizerTest.php`:

```php
<?php

use App\Services\Scrapers\MakeNormalizer;

it('canonicalizes known abbreviations to full names', function (string $input, string $expected): void {
    expect((new MakeNormalizer)->canonicalize($input))->toBe($expected);
})->with([
    'VW -> Volkswagen' => ['VW', 'Volkswagen'],
    'Alfa -> Alfa Romeo' => ['Alfa', 'Alfa Romeo'],
    'spaced Mercedes' => ['Mercedes Benz', 'Mercedes-Benz'],
]);

it('trims and collapses internal whitespace', function (): void {
    expect((new MakeNormalizer)->canonicalize('  Alfa   Romeo '))->toBe('Alfa Romeo');
});

it('leaves unknown names unchanged apart from cleanup', function (): void {
    expect((new MakeNormalizer)->canonicalize('BMW'))->toBe('BMW');
});

it('deduplicates case-insensitively and merges aliases', function (): void {
    $result = (new MakeNormalizer)->dedupe(['BMW', 'bmw', 'VW', 'Volkswagen', 'Audi', '']);

    expect($result)->toEqualCanonicalizing(['BMW', 'Volkswagen', 'Audi']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=MakeNormalizerTest`
Expected: FAIL — `Class "App\Services\Scrapers\MakeNormalizer" not found`.

- [ ] **Step 3: Write the implementation**

In `app/Services/Scrapers/MakeNormalizer.php`:

```php
<?php

namespace App\Services\Scrapers;

class MakeNormalizer
{
    /**
     * Known cross-platform variants and abbreviations mapped to their canonical
     * full name. Keyed on the lowercased, whitespace-collapsed input.
     *
     * @var array<string, string>
     */
    private array $aliases = [
        'vw' => 'Volkswagen',
        'mercedes' => 'Mercedes-Benz',
        'mercedes benz' => 'Mercedes-Benz',
        'alfa' => 'Alfa Romeo',
    ];

    public function canonicalize(string $name): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $name));

        return $this->aliases[mb_strtolower($clean)] ?? $clean;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    public function dedupe(array $names): array
    {
        $seen = [];

        foreach ($names as $name) {
            $canonical = $this->canonicalize($name);

            if ($canonical === '') {
                continue;
            }

            $seen[mb_strtolower($canonical)] = $canonical;
        }

        return array_values($seen);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=MakeNormalizerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Scrapers/MakeNormalizer.php tests/Unit/MakeNormalizerTest.php
git commit -m "feat: MakeNormalizer for canonical make deduplication"
```

---

### Task 3: Car24Scraper::scrapeMakes() + base default

**Files:**
- Modify: `app/Services/Scrapers/Scraper.php` (add default `scrapeMakes()`)
- Modify: `app/Services/Scrapers/Car24Scraper.php` (add `scrapeMakes()`, import `Illuminate\Support\Arr` if not present)
- Test: `tests/Feature/Scrapers/Car24ScraperTest.php` (append)

**Interfaces:**
- Produces: `Scraper::scrapeMakes(): array` (default `[]`); `Car24Scraper::scrapeMakes(): array` returning `array<int, array{name: string, slug: string|null}>`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Scrapers/Car24ScraperTest.php`:

```php
it('scrapes and merges car24 popular and other makes', function (): void {
    Http::fake(['api.car24.bg/*' => Http::response([
        'status' => 'success',
        'data' => [
            'marki' => [['Audi', 'audi'], ['BMW', 'bmw'], ['VW', 'vw']],
            'markiOther' => [
                ['brand' => 'Abarth', 'count' => '27', 'sef' => 'abarth'],
                ['brand' => 'Acura', 'count' => '46', 'sef' => 'acura'],
            ],
        ],
    ])]);

    $makes = (new Car24Scraper)->scrapeMakes();

    expect($makes)->toContain(['name' => 'VW', 'slug' => 'vw'])
        ->and($makes)->toContain(['name' => 'Abarth', 'slug' => 'abarth'])
        ->and(collect($makes)->pluck('name')->all())->toContain('Audi', 'BMW', 'Acura');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=Car24ScraperTest`
Expected: FAIL — `Call to undefined method ...::scrapeMakes()`.

- [ ] **Step 3: Add the base default**

In `app/Services/Scrapers/Scraper.php`, add after the `scrape()` method:

```php
    /**
     * Scrape the platform's list of car makes.
     *
     * @return array<int, array{name: string, slug: string|null}>
     */
    public function scrapeMakes(): array
    {
        return [];
    }
```

- [ ] **Step 4: Implement `Car24Scraper::scrapeMakes()`**

In `app/Services/Scrapers/Car24Scraper.php`, add the method (the file already imports `Illuminate\Support\Arr` and `Illuminate\Support\Facades\Http`):

```php
    /**
     * @return array<int, array{name: string, slug: string|null}>
     *
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public function scrapeMakes(): array
    {
        $response = Http::withHeaders($this->browserHeaders())
            ->get('https://api.car24.bg/mobile_api/brands');

        if (! $response->successful()) {
            return [];
        }

        $popular = Arr::map(
            $response->json('data.marki', []),
            fn (array $pair): array => ['name' => $pair[0], 'slug' => $pair[1] ?? null],
        );

        $other = Arr::map(
            $response->json('data.markiOther', []),
            fn (array $brand): array => ['name' => $brand['brand'], 'slug' => $brand['sef'] ?? null],
        );

        return [...$popular, ...$other];
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=Car24ScraperTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Scrapers/Scraper.php app/Services/Scrapers/Car24Scraper.php tests/Feature/Scrapers/Car24ScraperTest.php
git commit -m "feat: scrapeMakes() for car24 + base default"
```

---

### Task 4: AutoBgScraper::scrapeMakes() + scrapeModels()

**Files:**
- Modify: `app/Services/Scrapers/AutoBgScraper.php`
- Test: `tests/Feature/Scrapers/AutoBgScraperTest.php` (append)

**Interfaces:**
- Produces:
  - `AutoBgScraper::scrapeMakes(): array` → `array<int, array{name: string, slug: string|null}>` (deduped by slug).
  - `AutoBgScraper::scrapeModels(string $makeSlug): array` → `array<int, array{name: string, slug: string}>` (deduped by slug).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Scrapers/AutoBgScraperTest.php`:

```php
it('scrapes auto.bg makes from the category page links', function (): void {
    $html = '<html><body>'
        .'<a href="/obiavi/avtomobili-dzhipove/audi">Audi</a>'
        .'<a href="/obiavi/avtomobili-dzhipove/bmw">BMW</a>'
        .'<a href="/obiavi/avtomobili-dzhipove/alfa-romeo">Alfa Romeo</a>'
        .'<a href="/obiavi/avtomobili-dzhipove/bmw/320">320</a>'
        .'<a href="/obiavi/avtomobili-dzhipove/bmw/page/2">2</a>'
        .'</body></html>';
    Http::fake(['www.auto.bg/*' => Http::response($html)]);

    $makes = (new AutoBgScraper)->scrapeMakes();

    expect($makes)->toEqual([
        ['name' => 'Audi', 'slug' => 'audi'],
        ['name' => 'BMW', 'slug' => 'bmw'],
        ['name' => 'Alfa Romeo', 'slug' => 'alfa-romeo'],
    ]);
});

it('scrapes auto.bg models for a make, skipping pagination and duplicates', function (): void {
    $html = '<html><body>'
        .'<a href="/obiavi/avtomobili-dzhipove/bmw/320">320</a>'
        .'<a href="/obiavi/avtomobili-dzhipove/bmw/x5">X5</a>'
        .'<a href="/obiavi/avtomobili-dzhipove/bmw/320">320</a>'
        .'<a href="/obiavi/avtomobili-dzhipove/bmw/page/2">next</a>'
        .'</body></html>';
    Http::fake(['www.auto.bg/*' => Http::response($html)]);

    $models = (new AutoBgScraper)->scrapeModels('bmw');

    expect($models)->toEqual([
        ['name' => '320', 'slug' => '320'],
        ['name' => 'X5', 'slug' => 'x5'],
    ]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=AutoBgScraperTest`
Expected: FAIL — `Call to undefined method ...::scrapeMakes()`.

- [ ] **Step 3: Implement both methods**

In `app/Services/Scrapers/AutoBgScraper.php`, add (the file already imports `Crawler` and `Http`):

```php
    /**
     * @return array<int, array{name: string, slug: string|null}>
     *
     * @throws ConnectionException
     */
    public function scrapeMakes(): array
    {
        $response = Http::withHeaders($this->browserHeaders())
            ->get('https://www.auto.bg/obiavi/avtomobili-dzhipove');

        if (! $response->successful()) {
            return [];
        }

        $crawler = new Crawler($response->body());
        $makes = [];

        $crawler->filter('a[href^="/obiavi/avtomobili-dzhipove/"]')->each(function (Crawler $node) use (&$makes): void {
            $href = (string) $node->attr('href');

            if (! preg_match('#^/obiavi/avtomobili-dzhipove/([a-z0-9-]+)$#', $href, $matches)) {
                return;
            }

            $name = trim($node->text(''));

            if ($name !== '') {
                $makes[$matches[1]] = ['name' => $name, 'slug' => $matches[1]];
            }
        });

        return array_values($makes);
    }

    /**
     * @return array<int, array{name: string, slug: string}>
     *
     * @throws ConnectionException
     */
    public function scrapeModels(string $makeSlug): array
    {
        $response = Http::withHeaders($this->browserHeaders())
            ->get("https://www.auto.bg/obiavi/avtomobili-dzhipove/{$makeSlug}");

        if (! $response->successful()) {
            return [];
        }

        $crawler = new Crawler($response->body());
        $models = [];
        $pattern = '#^/obiavi/avtomobili-dzhipove/'.preg_quote($makeSlug, '#').'/([a-z0-9-]+)$#';

        $crawler->filter('a[href^="/obiavi/avtomobili-dzhipove/'.$makeSlug.'/"]')->each(function (Crawler $node) use (&$models, $pattern): void {
            $href = (string) $node->attr('href');

            if (! preg_match($pattern, $href, $matches)) {
                return;
            }

            $name = trim($node->text(''));

            if ($name !== '') {
                $models[$matches[1]] = ['name' => $name, 'slug' => $matches[1]];
            }
        });

        return array_values($models);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=AutoBgScraperTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Scrapers/AutoBgScraper.php tests/Feature/Scrapers/AutoBgScraperTest.php
git commit -m "feat: scrapeMakes() and scrapeModels() for auto.bg"
```

---

### Task 5: CarsBgScraper::scrapeMakes()

**Files:**
- Modify: `app/Services/Scrapers/CarsBgScraper.php` (ensure `Crawler` and `Http` imports exist)
- Test: `tests/Feature/Scrapers/CarsBgScraperTest.php` (append)

**Interfaces:**
- Produces: `CarsBgScraper::scrapeMakes(): array` → `array<int, array{name: string, slug: null}>`, skipping the "Всички" (All, `value="0"`) chip.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Scrapers/CarsBgScraperTest.php`:

```php
it('scrapes cars.bg makes from the brand chips, skipping "All"', function (): void {
    $html = '<html><body>'
        .'<div id="brandsList" class="mdc-chip-set">'
        .'<span class="mdc-chip__text"><input type="radio" name="brandId" id="brandId_0" value="0" checked /><label for="brandId_0">Всички</label></span>'
        .'<span class="mdc-chip__text"><input type="radio" name="brandId" id="brandId_1" value="1" /><label for="brandId_1">BMW</label></span>'
        .'<span class="mdc-chip__text"><input type="radio" name="brandId" id="brandId_2" value="2" /><label for="brandId_2">Audi</label></span>'
        .'</div></body></html>';
    Http::fake(['www.cars.bg/*' => Http::response($html)]);

    $makes = (new CarsBgScraper)->scrapeMakes();

    expect($makes)->toEqual([
        ['name' => 'BMW', 'slug' => null],
        ['name' => 'Audi', 'slug' => null],
    ]);
});
```

If the test file does not already import `Http`, add `use Illuminate\Support\Facades\Http;` and `use App\Services\Scrapers\CarsBgScraper;` at the top.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=CarsBgScraperTest`
Expected: FAIL — `Call to undefined method ...::scrapeMakes()`.

- [ ] **Step 3: Implement the method**

In `app/Services/Scrapers/CarsBgScraper.php`, add (confirm `use Symfony\Component\DomCrawler\Crawler;` and `use Illuminate\Support\Facades\Http;` are present; add them if missing):

```php
    /**
     * @return array<int, array{name: string, slug: null}>
     *
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public function scrapeMakes(): array
    {
        $response = Http::withHeaders($this->browserHeaders())
            ->get('https://www.cars.bg/');

        if (! $response->successful()) {
            return [];
        }

        $crawler = new Crawler($response->body());
        $makes = [];

        $crawler->filter('#brandsList .mdc-chip__text')->each(function (Crawler $node) use (&$makes): void {
            $input = $node->filter('input[name="brandId"]');

            if ($input->count() > 0 && $input->attr('value') === '0') {
                return;
            }

            $label = $node->filter('label');
            $name = $label->count() > 0 ? trim($label->text('')) : '';

            if ($name !== '') {
                $makes[] = ['name' => $name, 'slug' => null];
            }
        });

        return $makes;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=CarsBgScraperTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Scrapers/CarsBgScraper.php tests/Feature/Scrapers/CarsBgScraperTest.php
git commit -m "feat: scrapeMakes() for cars.bg"
```

---

### Task 6: MobileBgScraper::scrapeMakes()

**Files:**
- Modify: `app/Services/Scrapers/MobileBgScraper.php` (already imports `Crawler` and `Http`)
- Test: `tests/Feature/Scrapers/MobileBgScraperTest.php` (append)

**Interfaces:**
- Produces: `MobileBgScraper::scrapeMakes(): array` → `array<int, array{name: string, slug: null}>`, reading the first `<span>` of each `#akSearchMarki .a` entry.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Scrapers/MobileBgScraperTest.php`:

```php
it('scrapes mobile.bg makes from the autocomplete menu', function (): void {
    $html = '<html><body>'
        .'<div class="akSearchMarki" id="akSearchMarki"><div class="scroll">'
        .'<p>-</p>'
        .'<div class="a" data-popular="true"><span>Mercedes-Benz</span> <span>24075</span></div>'
        .'<div class="a"><span>BMW</span> <span>19000</span></div>'
        .'</div></div></body></html>';
    Http::fake(['www.mobile.bg/*' => Http::response($html)]);

    $makes = (new MobileBgScraper)->scrapeMakes();

    expect($makes)->toEqual([
        ['name' => 'Mercedes-Benz', 'slug' => null],
        ['name' => 'BMW', 'slug' => null],
    ]);
});
```

If the test file does not already import `MobileBgScraper` / `Http`, add `use App\Services\Scrapers\MobileBgScraper;` and `use Illuminate\Support\Facades\Http;`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=MobileBgScraperTest`
Expected: FAIL — `Call to undefined method ...::scrapeMakes()`.

- [ ] **Step 3: Implement the method**

In `app/Services/Scrapers/MobileBgScraper.php`, add:

```php
    /**
     * @return array<int, array{name: string, slug: null}>
     *
     * @throws ConnectionException
     */
    public function scrapeMakes(): array
    {
        $response = Http::withHeaders($this->browserHeaders())
            ->get('https://www.mobile.bg/');

        if (! $response->successful()) {
            return [];
        }

        $crawler = new Crawler($response->body());
        $makes = [];

        $crawler->filter('#akSearchMarki .a')->each(function (Crawler $node) use (&$makes): void {
            $spans = $node->filter('span');

            if ($spans->count() === 0) {
                return;
            }

            $name = trim($spans->first()->text(''));

            if ($name !== '') {
                $makes[] = ['name' => $name, 'slug' => null];
            }
        });

        return $makes;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=MobileBgScraperTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Scrapers/MobileBgScraper.php tests/Feature/Scrapers/MobileBgScraperTest.php
git commit -m "feat: scrapeMakes() for mobile.bg"
```

---

### Task 7: `scrape:makes-models` command

**Files:**
- Create: `app/Console/Commands/ScrapeMakesModelsCommand.php`
- Test: `tests/Feature/Commands/ScrapeMakesModelsCommandTest.php`

**Interfaces:**
- Consumes: all four scrapers' `scrapeMakes()`, `AutoBgScraper::scrapeModels(string)`, `MakeNormalizer::canonicalize()` / `dedupe()`, `CarMake`, `CarModel`.
- Produces: Artisan command `scrape:makes-models`.

- [ ] **Step 1: Generate the command**

Run: `php artisan make:command ScrapeMakesModelsCommand --no-interaction`

- [ ] **Step 2: Write the failing test**

In `tests/Feature/Commands/ScrapeMakesModelsCommandTest.php`:

```php
<?php

use App\Models\CarMake;
use App\Models\CarModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeMakeSources(): void
{
    Http::fake([
        'api.car24.bg/*' => Http::response([
            'status' => 'success',
            'data' => [
                'marki' => [['VW', 'vw']],
                'markiOther' => [['brand' => 'BMW', 'count' => '1', 'sef' => 'bmw']],
            ],
        ]),
        'www.cars.bg/*' => Http::response(
            '<div id="brandsList">'
            .'<span class="mdc-chip__text"><input name="brandId" value="0"/><label>Всички</label></span>'
            .'<span class="mdc-chip__text"><input name="brandId" value="1"/><label>BMW</label></span>'
            .'</div>'
        ),
        'www.mobile.bg/*' => Http::response(
            '<div id="akSearchMarki"><div class="scroll">'
            .'<div class="a"><span>Volkswagen</span> <span>1</span></div>'
            .'</div></div>'
        ),
        'www.auto.bg/obiavi/avtomobili-dzhipove/bmw' => Http::response(
            '<a href="/obiavi/avtomobili-dzhipove/bmw/320">320</a>'
            .'<a href="/obiavi/avtomobili-dzhipove/bmw/x5">X5</a>'
        ),
        'www.auto.bg/obiavi/avtomobili-dzhipove/volkswagen' => Http::response(
            '<a href="/obiavi/avtomobili-dzhipove/volkswagen/golf">Golf</a>'
        ),
        'www.auto.bg/obiavi/avtomobili-dzhipove' => Http::response(
            '<a href="/obiavi/avtomobili-dzhipove/bmw">BMW</a>'
            .'<a href="/obiavi/avtomobili-dzhipove/volkswagen">Volkswagen</a>'
        ),
    ]);
}

it('scrapes, dedupes and stores makes and models', function (): void {
    fakeMakeSources();

    $this->artisan('scrape:makes-models')->assertSuccessful();

    expect(CarMake::pluck('name')->all())->toEqualCanonicalizing(['Volkswagen', 'BMW'])
        ->and(CarMake::where('name', 'VW')->exists())->toBeFalse();

    $bmw = CarMake::where('name', 'BMW')->first();
    expect($bmw->models->pluck('name')->all())->toEqualCanonicalizing(['320', 'X5']);

    $vw = CarMake::where('name', 'Volkswagen')->first();
    expect($vw->models->pluck('name')->all())->toBe(['Golf']);
});

it('is idempotent across repeated runs', function (): void {
    fakeMakeSources();

    $this->artisan('scrape:makes-models')->assertSuccessful();
    $this->artisan('scrape:makes-models')->assertSuccessful();

    expect(CarMake::count())->toBe(2)
        ->and(CarModel::count())->toBe(3);
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --compact --filter=ScrapeMakesModelsCommandTest`
Expected: FAIL — command not yet wired (default generated `handle()`).

- [ ] **Step 4: Write the command**

Replace `app/Console/Commands/ScrapeMakesModelsCommand.php` with:

```php
<?php

namespace App\Console\Commands;

use App\Misc\LogChannels;
use App\Models\CarMake;
use App\Models\CarModel;
use App\Services\Scrapers\AutoBgScraper;
use App\Services\Scrapers\Car24Scraper;
use App\Services\Scrapers\CarsBgScraper;
use App\Services\Scrapers\MakeNormalizer;
use App\Services\Scrapers\MobileBgScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ScrapeMakesModelsCommand extends Command
{
    protected $signature = 'scrape:makes-models';

    protected $description = 'Scrape, deduplicate and store car makes (all platforms) and models (auto.bg)';

    /**
     * @var array<int, class-string<\App\Services\Scrapers\Scraper>>
     */
    private array $makeSources = [
        Car24Scraper::class,
        AutoBgScraper::class,
        CarsBgScraper::class,
        MobileBgScraper::class,
    ];

    public function handle(MakeNormalizer $normalizer): int
    {
        $pooled = [];

        foreach ($this->makeSources as $source) {
            try {
                foreach (app($source)->scrapeMakes() as $make) {
                    $pooled[] = $make['name'];
                }
            } catch (\Throwable $e) {
                Log::channel(LogChannels::LISTINGS)->error("Failed to scrape makes from {$source} - {$e->getMessage()}");
            }
        }

        $canonicalNames = $normalizer->dedupe($pooled);

        foreach ($canonicalNames as $name) {
            CarMake::firstOrCreate(['name' => $name], ['slug' => Str::slug($name)]);
        }

        $this->info(count($pooled).' makes scraped, '.count($canonicalNames).' after dedupe.');

        $this->scrapeModels($normalizer);

        return self::SUCCESS;
    }

    private function scrapeModels(MakeNormalizer $normalizer): void
    {
        $auto = app(AutoBgScraper::class);

        try {
            $makes = $auto->scrapeMakes();
        } catch (\Throwable $e) {
            Log::channel(LogChannels::LISTINGS)->error("Failed to scrape auto.bg makes for models - {$e->getMessage()}");

            return;
        }

        foreach ($makes as $make) {
            if (empty($make['slug'])) {
                continue;
            }

            $carMake = CarMake::where('name', $normalizer->canonicalize($make['name']))->first();

            if ($carMake === null) {
                continue;
            }

            try {
                $models = $auto->scrapeModels($make['slug']);
            } catch (\Throwable $e) {
                Log::channel(LogChannels::LISTINGS)->error("Failed to scrape auto.bg models for {$make['slug']} - {$e->getMessage()}");

                continue;
            }

            $seen = [];

            foreach ($models as $model) {
                $name = trim(preg_replace('/\s+/u', ' ', $model['name']));

                if ($name === '' || isset($seen[mb_strtolower($name)])) {
                    continue;
                }

                $seen[mb_strtolower($name)] = true;

                CarModel::firstOrCreate(
                    ['car_make_id' => $carMake->id, 'name' => $name],
                    ['slug' => $model['slug'] !== '' ? $model['slug'] : Str::slug($name)],
                );
            }

            $this->line("{$carMake->name}: ".count($seen).' models');
        }
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=ScrapeMakesModelsCommandTest`
Expected: PASS (2 passed).

- [ ] **Step 6: Run the full suite and Pint**

Run:
```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
```
Expected: all tests pass; Pint reports the touched files formatted.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/ScrapeMakesModelsCommand.php tests/Feature/Commands/ScrapeMakesModelsCommandTest.php
git commit -m "feat: scrape:makes-models command"
```

---

## Notes for the implementer

- **Running it for real** (the one-time goal): after all tasks pass, run `php artisan scrape:makes-models`. It hits live sites, so expect it to take a little while on the auto.bg model crawl (one request per make). It is safe to re-run — `firstOrCreate` makes it idempotent.
- **`Http::fake` pattern matching:** Laravel prepends `*` to every stub key, so `www.auto.bg/*` matches `https://www.auto.bg/...`, and an exact key like `www.auto.bg/obiavi/avtomobili-dzhipove` matches only that exact URL (not `.../bmw`). This is why the command test can register per-path auto.bg responses.
- **car24 brands shape (verified):** `data.marki` is an array of `[name, slug]` pairs; `data.markiOther` is an array of `{brand, count, sef}` objects.