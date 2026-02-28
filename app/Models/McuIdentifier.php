<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McuIdentifier extends Model
{
    protected $fillable = [
        'mcu_id',
        'identifier_type',
        'identifier_value',
        'channel',
        'marketplace',
        'region',
        'is_projection_identifier',
        'asin_unique',
        'meta',
    ];

    protected $casts = [
        'is_projection_identifier' => 'boolean',
        'meta' => 'array',
    ];

    public function mcu()
    {
        return $this->belongsTo(Mcu::class);
    }
}
