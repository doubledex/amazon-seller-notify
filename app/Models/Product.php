<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name',
        'status',
        'notes',
    ];

    public function identifiers()
    {
        return $this->hasMany(ProductIdentifier::class);
    }

    public function costLayers()
    {
        return $this->hasMany(ProductCostLayer::class);
    }
}
