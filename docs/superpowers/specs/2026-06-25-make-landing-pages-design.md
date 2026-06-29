# Make Landing Pages (SEO) — Design

**Date:** 2026-06-25
**Status:** Approved (pending spec review)

## Goal

Create one SEO-optimized landing page per car make, displaying that make's
listings, to capture transactional Bulgarian search queries ("{make} обяви",
"{make} автомобили"). Pages must be indexable, unique (not thin), and well
linked into the site.

## Research basis (why this shape)

The dominant Bulgarian ranker, mobile.bg, structures make pages as
`mobile.bg/obiavi/avtomobili-dzhipove/bmw`. Three signals drive the design:

1. **Bulgarian descriptive path word** (`obiavi` = listings/ads), matching how
   the bg-only audience searches, not an English path.
2. **Latin make slug** (`bmw`, `mercedes-benz`) — exactly what our existing
   `car_makes.slug` column holds.
3. **Subfolder hierarchy** consolidates domain authority (programmatic-SEO
   principle).

We have no category taxonomy, so the `avtomobili-dzhipove` segment is dropped.

## Decisions

- **URL:** `/obiavi/{make}` where `{make}` is the latin slug. Make page route
  name `make-listings`.
- **Hub:** dedicated `/marki` index page listing all makes (with active
  counts), route name `make-index`. Linked site-wide from the footer.
- **Page style:** pure lean — no live filter or sort controls. Default order is
  newest-first. (Full filtering remains on the main search page.)
- **Render only makes with active listings.** A make slug with zero active
  listings → 404. No empty/thin pages.

## Architecture & Components

### Routing (`routes/web.php`)
```php
Route::livewire('/marki', 'pages.make-index')->name('make-index');
Route::livewire('/obiavi/{make}', 'pages.make-listings')->name('make-listings');
```
Both are placed **above** existing routes only if needed; `/obiavi/{make}` does
not collide with `/cars/{carListing}`.

### Migration — unique slug index
`car_makes.slug` is currently non-unique (only `name` is unique). Add a unique
index on `slug` so it is a safe routing key. Pre-flight: verify no duplicate
slugs exist before adding the constraint; if any exist, resolve first.

### Livewire page: `pages.make-listings`
Single-file Volt-style component (matching `pages.car-listings`).
- `mount(string $make)`: resolve `CarMake::where('slug', $make)->firstOrFail()`.
  Abort 404 if the make has zero active listings.
- `#[Computed] listings()`: `CarListing::active()->where('car_make_id', $make->id)`
  ordered newest-first via `COALESCE(published_at, created_at) DESC`,
  `paginate(15)`. Uses `WithPagination`.
- `#[Computed] stats()`: active count, min/max price, top N models (for intro
  copy + internal links). Single grouped query where possible; eager-load to
  avoid N+1.
- `#[Computed] topModels()`: this make's models with active-listing counts,
  ordered by count desc, limited (e.g. 12), for the internal-links block.
- Renders the shared `<x-car-listing-card>` + pagination.

### Livewire page: `pages.make-index` (hub)
- Lists all makes that have active listings, alphabetically, with counts.
- Each item links to `route('make-listings', $make->slug)`.
- Self SEO head (title/meta/canonical).

### Shared component: `<x-car-listing-card :listing>`
Extract the inline listing-card markup currently in
`resources/views/livewire/pages/car-listings.blade.php` into a reusable
Blade component (`resources/views/components/car-listing-card.blade.php`).
Replace the inline usage in `car-listings.blade.php` with the component so both
the search page and make pages render identical cards. Helper methods currently
on the component (`getSource`, `formatImageUrl`) move with the card or become
small helpers the component can call.

## On-page SEO (anti-thin-content core)

Injected via the existing `@stack('seo')` convention (see seo-conventions memory).

- `<title>`: `{Make} автомобили — {count} обяви и цени`
- Meta description (data-driven):
  `Разгледай {count} обяви за {Make} от mobile.bg, cars.bg, auto.bg и car24.bg. Цени от {minPrice} до {maxPrice} лв.`
- `<h1>`: `{Make} обяви`, followed by a short **data-driven intro paragraph**
  (count, price range, top models) so each page carries unique content.
- Self-referencing `<link rel="canonical">` on every page, including paginated
  pages (each page canonical to itself).
- **Schema.org** (marketing-skills:schema): `BreadcrumbList`
  (Начало › Марки › {Make}) and `CollectionPage`. JSON-LD in the seo stack.

## Internal linking (indexation)

- **Hub → spokes:** `/marki` links to every make page; footer links to `/marki`
  site-wide (prevents orphan pages).
- **Spoke → spokes:** each make page links to its top models (deep links into
  the existing search filter, `route('car-listings', ['make' => slug, 'model' => slug])`)
  and back to `/marki`.
- Breadcrumb nav on each make page.

## Sitemap (`SeoController`)

- Add a dedicated `sitemap-makes.xml` (route + view) listing every make page
  URL for makes with active listings (~150 URLs).
- Register it in the existing sitemap index (`seo.sitemap-index` view) alongside
  the paginated listing sitemaps.
- Cached via the existing `SitemapCache` mechanism.

## Error handling

- Unknown slug → 404 (`firstOrFail`).
- Known slug with zero active listings → 404.
- Pagination beyond last page → standard Laravel behavior (empty page); canonical
  still self-references.

## Testing (Pest feature tests)

- `make-listings` route resolves for a make with active listings (200).
- Unknown make slug → 404.
- Make with only inactive listings → 404.
- Page lists only that make's active listings (no others leak in).
- Title, meta description, H1, and self-canonical are present and correct.
- `/marki` hub lists makes with active listings and links to their pages.
- Sitemap index includes `sitemap-makes.xml`; the makes sitemap lists make URLs
  and excludes makes with no active listings.
- Shared `<x-car-listing-card>` renders a listing's key fields (regression guard
  after extraction).

Use model factories (CarMake/CarModel/CarListing) and their states.

## Skills to apply during implementation

- **test-driven-development / pest-testing** — write failing tests first.
- **livewire-development** — both page components.
- **tailwindcss-development** — card extraction + hub/landing styling.
- **marketing-skills:schema** — BreadcrumbList / CollectionPage JSON-LD.
- **verification-before-completion** — run the suite + Pint before claiming done.

## Out of scope (YAGNI)

- Per-model landing pages (`/obiavi/bmw/x5`) — deferred; models stay reachable
  via the search filter and the on-page model links.
- Live filters/sort on make pages.
- Cyrillic slugs / redirects.
