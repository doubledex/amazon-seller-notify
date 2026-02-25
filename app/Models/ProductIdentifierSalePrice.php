<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductIdentifierSalePrice extends Model
{
    protected $fillable = [
        'product_identifier_id',
        'effective_from',
        'effective_to',
        'currency',
        'sale_price',
        'source',
        'notes',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'sale_price' => 'decimal:4',
    ];

    public function productIdentifier()
    {
        return $this->belongsTo(ProductIdentifier::class);
    }
}
