<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductIdentifier extends Model
{
    protected $fillable = [
        'product_id',
        'identifier_type',
        'identifier_value',
        'marketplace_id',
        'region',
        'is_primary',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function costLayers()
    {
        return $this->hasMany(ProductIdentifierCostLayer::class);
    }

    protected $casts = [
        'is_primary' => 'boolean',
    ];
}
