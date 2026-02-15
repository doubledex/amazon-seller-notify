<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CityGeo extends Model
{
    protected $fillable = [
        'country_code',
        'city',
        'region',
        'lookup_hash',
        'lat',
        'lng',
        'source',
        'last_used_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public static function normalizeCountry(string $countryCode): string
    {
        return strtoupper(trim($countryCode));
    }

    public static function normalizeCity(string $city): string
    {
        return strtoupper(trim($city));
    }

    public static function normalizeRegion(?string $region): string
    {
        return strtoupper(trim((string) $region));
    }

    public static function lookupHash(string $countryCode, string $city, ?string $region = null): string
    {
        $normalized = self::normalizeCountry($countryCode)
            . '|'
            . self::normalizeCity($city)
            . '|'
            . self::normalizeRegion($region);

        return sha1($normalized);
    }
}
