<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductCostLayer extends Model
{
    protected $fillable = [
        'product_id',
        'effective_from',
        'effective_to',
        'unit_landed_cost',
        'currency',
        'source',
        'notes',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
