<?php

namespace App\Services;

use App\Models\Marketplace;
use Illuminate\Support\Facades\Log;
use SellingPartnerApi\SellingPartnerApi;

class MarketplaceService
{
    public function getMarketplaceIds(SellingPartnerApi $connector): array
    {
        $marketplaces = Marketplace::query()->orderBy('id')->get();
        if ($marketplaces->isEmpty()) {
            $marketplaces = $this->syncFromApi($connector);
        }

        if ($marketplaces->isEmpty()) {
            return array_values(array_filter(config('services.amazon_sp_api.marketplace_ids') ?? []));
        }

        return $marketplaces->pluck('id')->all();
    }

    public function getMarketplacesForUi(SellingPartnerApi $connector): array
    {
        $marketplaces = Marketplace::query()->orderBy('id')->get();
        if ($marketplaces->isEmpty()) {
            $marketplaces = $this->syncFromApi($connector);
        }

        $result = [];
        foreach ($marketplaces as $marketplace) {
            $result[$marketplace->id] = [
                'id' => $marketplace->id,
                'name' => $marketplace->name ?? '',
                'countryCode' => $marketplace->country_code ?? '',
                'country' => $marketplace->country_code ?? '',
                'defaultCurrency' => $marketplace->default_currency ?? '',
                'defaultLanguage' => $marketplace->default_language ?? '',
                'flag' => $this->flagFromCountryCode($marketplace->country_code ?? ''),
            ];
        }

        return $result;
    }

    public function getMarketplaceMap(): array
    {
        return Marketplace::query()
            ->orderBy('id')
            ->pluck('country_code', 'id')
            ->all();
    }

    public function syncFromApi(SellingPartnerApi $connector)
    {
        try {
            $sellersApi = $connector->sellersV1();
            $response = $sellersApi->getMarketplaceParticipations();

            if ($response->status() >= 400) {
                Log::warning('Marketplace sync failed', ['status' => $response->status(), 'body' => $response->body()]);
                return collect();
            }

            $data = $response->json();
            $rows = [];

            if (isset($data['payload'])) {
                foreach ($data['payload'] as $participation) {
                    if (!isset($participation['marketplace'])) {
                        continue;
                    }

                    $marketplace = $participation['marketplace'];
                    $rows[] = [
                        'id' => $marketplace['id'] ?? null,
                        'name' => $marketplace['name'] ?? null,
                        'country_code' => $marketplace['countryCode'] ?? null,
                        'default_currency' => $marketplace['defaultCurrencyCode'] ?? null,
                        'default_language' => $marketplace['defaultLanguageCode'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            $rows = array_values(array_filter($rows, fn ($row) => !empty($row['id'])));
            if (!empty($rows)) {
                Marketplace::upsert(
                    $rows,
                    ['id'],
                    ['name', 'country_code', 'default_currency', 'default_language', 'updated_at']
                );
            }

            return Marketplace::query()->orderBy('id')->get();
        } catch (\Exception $e) {
            Log::warning('Marketplace sync exception', ['error' => $e->getMessage()]);
            return collect();
        }
    }

    private function flagFromCountryCode(string $countryCode): string
    {
        $countryCode = strtoupper(trim($countryCode));
        if (strlen($countryCode) !== 2) {
            return '';
        }

        if (function_exists('mb_chr')) {
            $first = 0x1F1E6 + (ord($countryCode[0]) - 65);
            $second = 0x1F1E6 + (ord($countryCode[1]) - 65);
            return mb_chr($first, 'UTF-8') . mb_chr($second, 'UTF-8');
        }

        if (class_exists('\IntlChar')) {
            $first = 0x1F1E6 + (ord($countryCode[0]) - 65);
            $second = 0x1F1E6 + (ord($countryCode[1]) - 65);
            return \IntlChar::chr($first) . \IntlChar::chr($second);
        }

        $fallback = [
            'AE' => '🇦🇪',
            'AU' => '🇦🇺',
            'BE' => '🇧🇪',
            'CL' => '🇨🇱',
            'DE' => '🇩🇪',
            'EG' => '🇪🇬',
            'ES' => '🇪🇸',
            'FR' => '🇫🇷',
            'GB' => '🇬🇧',
            'IN' => '🇮🇳',
            'IT' => '🇮🇹',
            'NL' => '🇳🇱',
            'PL' => '🇵🇱',
            'SA' => '🇸🇦',
            'SE' => '🇸🇪',
            'SG' => '🇸🇬',
            'TR' => '🇹🇷',
            'ZA' => '🇿🇦',
        ];

        return $fallback[$countryCode] ?? '';
    }
}
