<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsFcLocation extends Model
{
    protected $fillable = [
        'fulfillment_center_id',
        'city',
        'state',
        'country_code',
        'label',
    ];
}
