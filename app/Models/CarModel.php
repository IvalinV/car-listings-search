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
        return $this->belongsTo(CarMake::class, 'car_make_id');
    }
}
