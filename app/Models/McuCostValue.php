<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McuCostValue extends Model
{
    protected $fillable = [
        'mcu_id',
        'supplier',
        'description',
        'amount',
        'currency',
        'effective_from',
        'effective_to',
        'marketplace',
        'region',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function mcu()
    {
        return $this->belongsTo(Mcu::class);
    }
}
