<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PostalGeocoder
{
    public function geocode(string $countryCode, string $postalCode): ?array
    {
        $countryCode = strtoupper(trim($countryCode));
        $postalCode = strtoupper(trim($postalCode));

        if ($countryCode === '' || $postalCode === '') {
            return null;
        }

        if ($countryCode === 'GB') {
            $result = $this->geocodePostcodesIo($postalCode);
            return $result ?: $this->geocodeNominatimPostal($countryCode, $postalCode);
        }

        $result = $this->geocodeZippopotam($countryCode, $postalCode);
        return $result ?: $this->geocodeNominatimPostal($countryCode, $postalCode);
    }

    public function geocodeCity(string $countryCode, string $city, ?string $region = null): ?array
    {
        $countryCode = strtoupper(trim($countryCode));
        $city = trim((string) $city);
        $region = trim((string) $region);

        if ($countryCode === '' || $city === '') {
            return null;
        }

        $params = [
            'format' => 'json',
            'limit' => 1,
            'countrycodes' => strtolower($countryCode),
            'city' => $city,
        ];

        if ($region !== '') {
            $params['state'] = $region;
        }

        $response = Http::timeout(6)
            ->withHeaders(['User-Agent' => 'amazon-seller-notify/1.0'])
            ->get('https://nominatim.openstreetmap.org/search', $params);

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();
        if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) {
            return null;
        }

        $lat = (float) $data[0]['lat'];
        $lng = (float) $data[0]['lon'];
        if ($lat == 0.0 || $lng == 0.0 || abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        return [
            'lat' => $lat,
            'lng' => $lng,
            'source' => 'nominatim',
        ];
    }

    private function geocodePostcodesIo(string $postalCode): ?array
    {
        $variants = [
            $postalCode,
            str_replace(' ', '', $postalCode),
        ];

        foreach ($variants as $variant) {
            $url = 'https://api.postcodes.io/postcodes/' . urlencode($variant);
            $response = Http::timeout(6)->get($url);

            if (!$response->successful()) {
                continue;
            }

            $data = $response->json();
            if (!isset($data['result']['latitude'], $data['result']['longitude'])) {
                continue;
            }

            $lat = (float) $data['result']['latitude'];
            $lng = (float) $data['result']['longitude'];
            if ($lat == 0.0 || $lng == 0.0 || abs($lat) > 90 || abs($lng) > 180) {
                continue;
            }

            return [
                'lat' => $lat,
                'lng' => $lng,
                'source' => 'postcodes.io',
            ];
        }

        return null;
    }

    private function geocodeZippopotam(string $countryCode, string $postalCode): ?array
    {
        $url = "https://api.zippopotam.us/{$countryCode}/" . urlencode($postalCode);
        $response = Http::timeout(6)->get($url);

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();
        if (!isset($data['places'][0]['latitude'], $data['places'][0]['longitude'])) {
            return null;
        }

        $lat = (float) $data['places'][0]['latitude'];
        $lng = (float) $data['places'][0]['longitude'];
        if ($lat == 0.0 || $lng == 0.0 || abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        return [
            'lat' => $lat,
            'lng' => $lng,
            'source' => 'zippopotam',
        ];
    }

    private function geocodeNominatimPostal(string $countryCode, string $postalCode): ?array
    {
        $variants = [
            $postalCode,
            preg_replace('/\\s+/', '', $postalCode),
        ];

        foreach ($variants as $variant) {
            if ($variant === '') {
                continue;
            }
            $params = [
                'format' => 'json',
                'limit' => 1,
                'countrycodes' => strtolower($countryCode),
                'postalcode' => $variant,
            ];

            $response = Http::timeout(6)
                ->withHeaders(['User-Agent' => 'amazon-seller-notify/1.0'])
                ->get('https://nominatim.openstreetmap.org/search', $params);

            if (!$response->successful()) {
                continue;
            }

            $data = $response->json();
            if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) {
                continue;
            }

            $lat = (float) $data[0]['lat'];
            $lng = (float) $data[0]['lon'];
            if ($lat == 0.0 || $lng == 0.0 || abs($lat) > 90 || abs($lng) > 180) {
                continue;
            }

            return [
                'lat' => $lat,
                'lng' => $lng,
                'source' => 'nominatim',
            ];
        }

        return null;
    }
}
