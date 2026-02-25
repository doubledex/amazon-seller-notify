<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductIdentifierCostLayer extends Model
{
    protected $fillable = [
        'product_identifier_id',
        'effective_from',
        'effective_to',
        'currency',
        'allocation_basis',
        'shipment_reference',
        'unit_landed_cost',
        'source',
        'notes',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'unit_landed_cost' => 'decimal:4',
    ];

    public function productIdentifier()
    {
        return $this->belongsTo(ProductIdentifier::class);
    }

    public function components()
    {
        return $this->hasMany(ProductIdentifierCostComponent::class, 'cost_layer_id');
    }

    public function recalculateUnitLandedCost(): void
    {
        $total = (float) $this->components()->sum('normalized_unit_amount');
        $this->unit_landed_cost = round($total, 4);
        $this->save();
    }
}
