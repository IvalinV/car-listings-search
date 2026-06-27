<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\CarListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CarListing extends Model
{
    /** @use HasFactory<CarListingFactory> */
    use HasFactory;

    /**
     * Placeholder price used by sources when a listing has no real price.
     * Excluded from filtering, sorting and stats so it never floods results.
     */
    public const UNPRICED_SENTINEL = 99999999;

    protected $guarded = [];

    /**
     * Placeholder / junk prices that sources use instead of a real value:
     * the explicit sentinel, repeated-digit prices (e.g. 1111111) and
     * sequential prices (e.g. 12345678). Only prices of 6 to 8 digits are
     * treated as junk: shorter prices (e.g. 5555) are plausible real values,
     * and the `price` column is decimal(10,2) so it cannot hold 9+ digits.
     *
     * @return list<int>
     */
    public static function junkPrices(): array
    {
        $prices = [self::UNPRICED_SENTINEL];

        foreach (range(6, 8) as $length) {
            foreach (range(1, 9) as $digit) {
                $prices[] = (int) str_repeat((string) $digit, $length);
            }

            $prices[] = (int) substr('12345678', 0, $length);
        }

        return array_values(array_unique($prices));
    }

    protected function casts(): array
    {
        return [
            'source_urls' => 'array',
            'source_dates' => 'array',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * SEO-friendly route key: "{id}-{title-slug}" (e.g. 1737405-bmw-x5-xdrive40i).
     */
    public function getRouteKey(): string
    {
        $slug = Str::slug((string) $this->title);

        return $slug === '' ? (string) $this->getKey() : $this->getKey().'-'.$slug;
    }

    /**
     * Resolve route bindings by the leading numeric id, ignoring the cosmetic slug,
     * so both "/cars/123" and "/cars/123-anything" resolve and old links keep working.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->where($field ?? $this->getKeyName(), (int) Str::before((string) $value, '-'))->first();
    }

    public function getPriceBGNAttribute(): float
    {
        return $this->price * 1.955883;
    }

    /**
     * The earliest date the car was created on any source ("Създадена").
     *
     * Each `source_dates` entry is a per-source map of `{created, updated}`
     * (legacy rows store a bare date string). This returns the minimum of the
     * created dates; the latest update is already stored in `published_at`.
     */
    public function firstPublishedAt(): ?Carbon
    {
        $created = collect($this->source_dates ?? [])
            ->map(fn ($entry) => is_array($entry) ? ($entry['created'] ?? $entry['updated'] ?? null) : $entry)
            ->filter();

        return $created->isEmpty() ? null : Carbon::parse($created->min());
    }

    /**
     * The date to show for a single source badge: its last update, falling back
     * to its creation date. Handles both the nested shape and legacy strings.
     */
    public function sourceDate(string $source): ?Carbon
    {
        $entry = ($this->source_dates ?? [])[$source] ?? null;

        if ($entry === null) {
            return null;
        }

        $value = is_array($entry) ? ($entry['updated'] ?? $entry['created'] ?? null) : $entry;

        return $value ? Carbon::parse($value) : null;
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(CarMake::class, 'car_make_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(CarModel::class, 'car_model_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePriced(Builder $query): Builder
    {
        return $query->whereNotIn('price', self::junkPrices());
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('title', 'like', '%'.$term.'%')
                ->orWhere('description', 'like', '%'.$term.'%');
        });
    }

    public function scopeFuelType(Builder $query, ?string $type): Builder
    {
        if (empty($type)) {
            return $query;
        }

        return $query->where('fuel_type', $type);
    }

    public function scopeTransmission(Builder $query, ?string $transmission): Builder
    {
        if (empty($transmission)) {
            return $query;
        }

        return $query->where('transmission', $transmission);
    }

    public function scopeLocation(Builder $query, ?string $location): Builder
    {
        if (empty($location)) {
            return $query;
        }

        return $query->where('location', $location);
    }

    public function scopePriceRange(Builder $query, ?int $min, ?int $max): Builder
    {
        if ($min !== null) {
            $query->where('price', '>=', $min);
        }

        if ($max !== null) {
            $query->where('price', '<=', $max);
        }

        return $query;
    }

    public function scopeYearRange(Builder $query, ?int $min, ?int $max): Builder
    {
        if ($min !== null) {
            $query->where('year', '>=', $min);
        }

        if ($max !== null) {
            $query->where('year', '<=', $max);
        }

        return $query;
    }

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
}
