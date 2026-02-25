<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductIdentifierCostComponent extends Model
{
    protected $fillable = [
        'cost_layer_id',
        'component_type',
        'amount',
        'amount_basis',
        'allocation_quantity',
        'allocation_unit',
        'normalized_unit_amount',
        'allocation_metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'allocation_quantity' => 'decimal:4',
        'normalized_unit_amount' => 'decimal:4',
        'allocation_metadata' => 'array',
    ];

    public function costLayer()
    {
        return $this->belongsTo(ProductIdentifierCostLayer::class, 'cost_layer_id');
    }
}
