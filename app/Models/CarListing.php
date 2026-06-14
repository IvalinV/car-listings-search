<?php

namespace App\Models;

use Database\Factories\CarListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CarListing extends Model
{
    /** @use HasFactory<CarListingFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_urls' => 'array',
            'is_active' => 'boolean',
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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
}
