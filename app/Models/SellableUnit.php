<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellableUnit extends Model
{
    protected $fillable = [
        'mcu_id',
        'packaged_weight',
        'packaged_length',
        'packaged_width',
        'packaged_height',
        'barcode',
    ];

    protected $casts = [
        'packaged_weight' => 'decimal:4',
        'packaged_length' => 'decimal:4',
        'packaged_width' => 'decimal:4',
        'packaged_height' => 'decimal:4',
    ];

    public function mcu()
    {
        return $this->belongsTo(Mcu::class);
    }

}
