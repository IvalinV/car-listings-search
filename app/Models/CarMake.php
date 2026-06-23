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

    /**
     * @return HasMany<CarListing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(CarListing::class, 'car_make_id');
    }
}
