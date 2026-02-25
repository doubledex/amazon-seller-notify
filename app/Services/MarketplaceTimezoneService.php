<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MarketplaceTimezoneService
{
    private const REGION_TIMEZONES = [
        'NA' => 'America/Los_Angeles',
        'EU' => 'Europe/London',
        'FE' => 'Asia/Tokyo',
    ];

    private const COUNTRY_TIMEZONES = [
        'AT' => 'Europe/Vienna',
        'BE' => 'Europe/Brussels',
        'BR' => 'America/Los_Angeles',
        'CA' => 'America/Los_Angeles',
        'CH' => 'Europe/Zurich',
        'DE' => 'Europe/Berlin',
        'DK' => 'Europe/Copenhagen',
        'ES' => 'Europe/Madrid',
        'FI' => 'Europe/Helsinki',
        'FR' => 'Europe/Paris',
        'GB' => 'Europe/London',
        'IE' => 'Europe/Dublin',
        'IT' => 'Europe/Rome',
        'LU' => 'Europe/Luxembourg',
        'MX' => 'America/Los_Angeles',
        'NL' => 'Europe/Amsterdam',
        'NO' => 'Europe/Oslo',
        'PL' => 'Europe/Warsaw',
        'SE' => 'Europe/Stockholm',
        'TR' => 'Europe/Istanbul',
        'UK' => 'Europe/London',
        'US' => 'America/Los_Angeles',
    ];

    private array $marketplaceCountryCache = [];

    public function localizeFromUtc(?string $utcDateTime, ?string $marketplaceId, ?string $region = null): array
    {
        $timezone = $this->resolveTimezone($marketplaceId, $region);

        if (!$utcDateTime) {
            return [
                'purchase_date_local' => null,
                'purchase_date_local_date' => null,
                'marketplace_timezone' => $timezone,
            ];
        }

        try {
            $utc = Carbon::parse($utcDateTime, 'UTC');
            $local = $utc->copy()->setTimezone($timezone);

            return [
                'purchase_date_local' => $local->format('Y-m-d H:i:s'),
                'purchase_date_local_date' => $local->toDateString(),
                'marketplace_timezone' => $timezone,
            ];
        } catch (\Throwable $e) {
            return [
                'purchase_date_local' => null,
                'purchase_date_local_date' => null,
                'marketplace_timezone' => $timezone,
            ];
        }
    }

    public function resolveTimezone(?string $marketplaceId, ?string $region = null): string
    {
        $marketplaceId = trim((string) $marketplaceId);

        $byMarketplace = (array) config('services.marketplace_timezones.by_marketplace_id', []);
        if ($marketplaceId !== '' && isset($byMarketplace[$marketplaceId])) {
            $tz = trim((string) $byMarketplace[$marketplaceId]);
            if ($tz !== '') {
                return $tz;
            }
        }

        $country = $this->countryForMarketplace($marketplaceId);

        $byCountry = (array) config('services.marketplace_timezones.by_country', []);
        if ($country !== '' && isset($byCountry[$country])) {
            $tz = trim((string) $byCountry[$country]);
            if ($tz !== '') {
                return $tz;
            }
        }

        if ($country !== '' && isset(self::COUNTRY_TIMEZONES[$country])) {
            return self::COUNTRY_TIMEZONES[$country];
        }

        $region = strtoupper(trim((string) $region));
        $byRegion = (array) config('services.marketplace_timezones.by_region', []);
        if ($region !== '' && isset($byRegion[$region])) {
            $tz = trim((string) $byRegion[$region]);
            if ($tz !== '') {
                return $tz;
            }
        }

        if ($region !== '' && isset(self::REGION_TIMEZONES[$region])) {
            return self::REGION_TIMEZONES[$region];
        }

        return 'UTC';
    }

    private function countryForMarketplace(string $marketplaceId): string
    {
        if ($marketplaceId === '') {
            return '';
        }

        if (array_key_exists($marketplaceId, $this->marketplaceCountryCache)) {
            return $this->marketplaceCountryCache[$marketplaceId];
        }

        $country = strtoupper(trim((string) DB::table('marketplaces')->where('id', $marketplaceId)->value('country_code')));
        $this->marketplaceCountryCache[$marketplaceId] = $country;

        return $country;
    }
}
