<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryState extends Model
{
    public const CREATED_AT = null;

    protected $fillable = [
        'mcu_id',
        'location',
        'on_hand',
        'reserved',
        'safety_buffer',
        'updated_at',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
    ];

    public function mcu()
    {
        return $this->belongsTo(Mcu::class);
    }

    public function getAvailableAttribute(): int
    {
        return (int) $this->on_hand - (int) $this->reserved - (int) $this->safety_buffer;
    }
}
