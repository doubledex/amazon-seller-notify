<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostalCodeGeo extends Model
{
    protected $fillable = [
        'country_code',
        'postal_code',
        'lat',
        'lng',
        'source',
    ];
}
