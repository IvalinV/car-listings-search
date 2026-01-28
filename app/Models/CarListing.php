<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarListing extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_urls' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function getPriceBGNAttribute()
    {
        return $this->price * 1.955883;
    }
}
