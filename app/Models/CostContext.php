<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostContext extends Model
{
    protected $fillable = [
        'mcu_id',
        'region',
        'currency',
        'landed_cost_per_unit',
        'effective_from',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'landed_cost_per_unit' => 'decimal:4',
    ];

    public function mcu()
    {
        return $this->belongsTo(Mcu::class);
    }
}
