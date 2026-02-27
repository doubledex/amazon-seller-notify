<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Family extends Model
{
    protected $fillable = [
        'name',
        'marketplace',
        'parent_asin',
    ];

    public function mcus()
    {
        return $this->hasMany(Mcu::class);
    }
}
