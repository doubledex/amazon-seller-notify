<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderShipAddress extends Model
{
    protected $fillable = [
        'order_id',
        'country_code',
        'postal_code',
        'city',
        'region',
        'raw_address',
    ];

    protected $casts = [
        'raw_address' => 'array',
    ];
}
