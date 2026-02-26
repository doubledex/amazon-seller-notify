<?php

namespace App\Services;

use App\Models\Marketplace;
use App\Models\UsFcLocation;

class FcLocationRegistryService
{
    /**
     * @var array<string, string>
     */
    private array $marketplaceCountryCache = [];

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function ingestRows(array $rows, ?string $marketplaceId = null): int
    {
        $now = now();
        $affected = 0;
        $byFc = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized = $this->normalizeRow($row);
            $fc = $this->normalizeFc((string) ($normalized['fc'] ?? ''));
            if ($fc === '') {
                continue;
            }

            $country = $this->normalizeCountry((string) ($normalized['country_code'] ?? ''));
            if ($country === '') {
                $country = $this->countryFromMarketplace($marketplaceId);
            }
            if ($country === '') {
                $country = 'US';
            }

            if (!isset($byFc[$fc])) {
                $byFc[$fc] = [
                    'country_code' => $country,
                    'city' => $this->cleanText((string) ($normalized['city'] ?? '')),
                    'state' => $this->cleanText((string) ($normalized['state'] ?? '')),
                    'lat' => $this->toCoordinate($normalized['lat'] ?? null, -90.0, 90.0),
                    'lng' => $this->toCoordinate($normalized['lng'] ?? null, -180.0, 180.0),
                ];
                continue;
            }

            if ($byFc[$fc]['country_code'] === '' && $country !== '') {
                $byFc[$fc]['country_code'] = $country;
            }
            if ($byFc[$fc]['city'] === '') {
                $byFc[$fc]['city'] = $this->cleanText((string) ($normalized['city'] ?? ''));
            }
            if ($byFc[$fc]['state'] === '') {
                $byFc[$fc]['state'] = $this->cleanText((string) ($normalized['state'] ?? ''));
            }
            if ($byFc[$fc]['lat'] === null) {
                $byFc[$fc]['lat'] = $this->toCoordinate($normalized['lat'] ?? null, -90.0, 90.0);
            }
            if ($byFc[$fc]['lng'] === null) {
                $byFc[$fc]['lng'] = $this->toCoordinate($normalized['lng'] ?? null, -180.0, 180.0);
            }
        }

        if (empty($byFc)) {
            return 0;
        }

        $existing = UsFcLocation::query()
            ->whereIn('fulfillment_center_id', array_keys($byFc))
            ->get()
            ->keyBy('fulfillment_center_id');

        foreach ($byFc as $fc => $candidate) {
            $location = $existing[$fc] ?? new UsFcLocation(['fulfillment_center_id' => $fc]);

            $changed = false;
            $city = (string) ($candidate['city'] ?? '');
            $state = (string) ($candidate['state'] ?? '');
            $lat = $candidate['lat'];
            $lng = $candidate['lng'];
            $country = (string) ($candidate['country_code'] ?? '');

            if ($location->country_code === null || trim((string) $location->country_code) === '') {
                $location->country_code = $country;
                $changed = true;
            }

            if ($city !== '' && trim((string) $location->city) === '') {
                $location->city = $city;
                $changed = true;
            }

            if ($state !== '' && trim((string) $location->state) === '') {
                $location->state = $state;
                $changed = true;
            }

            if ($location->lat === null && $lat !== null) {
                $location->lat = $lat;
                $changed = true;
            }

            if ($location->lng === null && $lng !== null) {
                $location->lng = $lng;
                $changed = true;
            }

            $label = $this->buildLabel($fc, (string) ($location->city ?? ''), (string) ($location->state ?? ''), (string) ($location->country_code ?? ''));
            if ($label !== '' && trim((string) $location->label) === '') {
                $location->label = $label;
                $changed = true;
            }

            if ($changed) {
                $location->location_source = 'report';
                $location->updated_at = $now;
                $location->save();
                $affected++;
            }
        }

        return $affected;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeRow(array $row): array
    {
        $flat = [];
        foreach ($row as $key => $value) {
            $flat[strtolower(trim((string) $key))] = is_scalar($value) || $value === null ? (string) ($value ?? '') : '';
        }

        return [
            'fc' => $this->pick($flat, [
                'fulfillment-center-id', 'fulfillment_center_id', 'fulfillment center id', 'fulfillmentcenterid',
                'warehouse-id', 'warehouse_id', 'warehouse id', 'warehouseid',
                'location-id', 'location_id', 'location id', 'locationid',
                'fulfillment center', 'fulfillment-center', 'fulfillment_center',
                'store', 'fc',
            ]),
            'city' => $this->pick($flat, [
                'city', 'warehouse-city', 'warehouse_city', 'warehouse city', 'fulfillment city',
                'fulfillment_city', 'fulfillment-city', 'location-city', 'location_city', 'location city',
            ]),
            'state' => $this->pick($flat, [
                'state', 'region', 'province', 'warehouse-state', 'warehouse_state', 'warehouse state',
                'fulfillment state', 'fulfillment_state', 'fulfillment-state',
                'location-state', 'location_state', 'location state',
            ]),
            'country_code' => $this->pick($flat, [
                'country', 'country_code', 'country-code', 'country code',
                'marketplace country', 'marketplace_country', 'marketplace-country',
            ]),
            'lat' => $this->pick($flat, [
                'lat', 'latitude', 'warehouse-lat', 'warehouse_lat', 'warehouse latitude',
            ]),
            'lng' => $this->pick($flat, [
                'lng', 'lon', 'long', 'longitude', 'warehouse-lng', 'warehouse_lon', 'warehouse_longitude',
            ]),
        ];
    }

    private function normalizeFc(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '' || !preg_match('/^(?=.*[A-Z])(?=.*\d)[A-Z0-9]{3,10}$/', $value)) {
            return '';
        }

        return $value;
    }

    private function normalizeCountry(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return '';
        }

        if ($value === 'UK') {
            return 'GB';
        }

        return preg_match('/^[A-Z]{2}$/', $value) ? $value : '';
    }

    private function countryFromMarketplace(?string $marketplaceId): string
    {
        $marketplaceId = trim((string) $marketplaceId);
        if ($marketplaceId === '') {
            return '';
        }
        if (isset($this->marketplaceCountryCache[$marketplaceId])) {
            return $this->marketplaceCountryCache[$marketplaceId];
        }

        $country = strtoupper(trim((string) Marketplace::query()->whereKey($marketplaceId)->value('country_code')));
        if ($country === 'UK') {
            $country = 'GB';
        }

        $this->marketplaceCountryCache[$marketplaceId] = $country;

        return $country;
    }

    private function cleanText(string $value): string
    {
        return trim($value);
    }

    private function toCoordinate(mixed $value, float $min, float $max): ?float
    {
        if (!is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '' || !is_numeric($string)) {
            return null;
        }

        $number = (float) $string;
        if ($number < $min || $number > $max) {
            return null;
        }

        return $number;
    }

    private function buildLabel(string $fc, string $city, string $state, string $countryCode): string
    {
        $parts = [];
        if (trim($city) !== '') {
            $parts[] = trim($city);
        }
        if (trim($state) !== '') {
            $parts[] = strtoupper(trim($state));
        }
        if (empty($parts) && trim($countryCode) !== '') {
            $parts[] = strtoupper(trim($countryCode));
        }

        return empty($parts) ? $fc : ($fc . ' - ' . implode(', ', $parts));
    }

    /**
     * @param array<string, string> $flat
     * @param array<int, string> $keys
     */
    private function pick(array $flat, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $flat)) {
                return (string) $flat[$key];
            }
        }

        return '';
    }
}
