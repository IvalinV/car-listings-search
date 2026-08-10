# Car Listings Search

A Bulgarian car-listings aggregator that scrapes multiple automotive platforms, deduplicates listings, normalizes their data, and serves a searchable catalog.

## Supported Sources

- [mobile.bg](https://mobile.bg)
- [auto.bg](https://auto.bg)
- [cars.bg](https://cars.bg)
- [car24.bg](https://car24.bg)

## Features

- **Multi-source scraping** via console commands and queue jobs.
- **Deduplication** using perceptual image hashing, falling back to a SHA256 hash of key listing attributes.
- **Data normalization** for prices, fuel types, transmissions, makes, and models.
- **Search & filter** interface built with Livewire.
- **SEO-friendly** sitemaps, robots.txt, and slugged detail URLs.
- **Cleanup job** that probes source URLs and deactivates removed listings.

## Tech Stack

- PHP 8.4
- Laravel 13
- Livewire 4
- Tailwind CSS v4
- PostgreSQL
- Pest PHP for testing

## Getting Started

```bash
composer setup
```

This installs dependencies, generates the application key, runs migrations, installs frontend packages, and builds assets.

## Development

```bash
composer dev
```

Runs the development server, queue listener, log tail, and Vite concurrently.

## Testing

```bash
php artisan test --compact
```

## Main Console Commands

| Command | Purpose |
| --- | --- |
| `scrape:listings-initially` | Scrape as many listings as possible during initial seeding. |
| `scrape:new-listings` | Scrape only new listings. |
| `scrape:listings-daily` | Scrape daily listings. |
| `scrape:makes-models` | Scrape and store car makes and models. |
| `scrape:mobilebg-catalog` | Ingest the full mobile.bg catalog, segmented by make. |
| `cleanup:removed-listings` | Probe source URLs and deactivate listings that have been removed. |
| `resolve:listing-makes-models` | Resolve make/model relationships for existing listings. |

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
