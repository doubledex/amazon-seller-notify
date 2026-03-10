<?php

namespace App\Services;

use App\Models\Marketplace;
use App\Services\Amazon\OfficialSpApiService;
use Illuminate\Support\Facades\Log;
use SellingPartnerApi\SellingPartnerApi;

class MarketplaceService
{
    public function __construct(
        private readonly ?RegionConfigService $regionConfigService = null,
        private readonly ?OfficialSpApiService $officialSpApiService = null
    ) {
    }

    public function getMarketplaceIds(?SellingPartnerApi $connector = null): array
    {
        $marketplaces = Marketplace::query()->orderBy('id')->get();
        if ($marketplaces->isEmpty()) {
            $regionService = $this->regionConfigService ?? new RegionConfigService();
            $defaultRegion = $regionService->defaultSpApiRegion();
            $marketplaces = $connector
                ? $this->syncFromApi($connector)
                : $this->syncFromOfficialApi($defaultRegion);
        }

        if ($marketplaces->isEmpty()) {
            return array_values(array_filter(config('services.amazon_sp_api.marketplace_ids') ?? []));
        }

        return $marketplaces->pluck('id')->all();
    }

    public function getMarketplacesForUi(?SellingPartnerApi $connector = null): array
    {
        $marketplaces = Marketplace::query()->orderBy('id')->get();
        if ($marketplaces->isEmpty()) {
            $regionService = $this->regionConfigService ?? new RegionConfigService();
            $defaultRegion = $regionService->defaultSpApiRegion();
            $marketplaces = $connector
                ? $this->syncFromApi($connector)
                : $this->syncFromOfficialApi($defaultRegion);
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

    public function getMarketplaceIdsForRegion(?string $region = null): array
    {
        $marketplaces = Marketplace::query()->orderBy('id')->get();
        if ($marketplaces->isEmpty()) {
            $regionService = $this->regionConfigService ?? new RegionConfigService();
            $resolvedRegion = strtoupper(trim((string) ($region ?: $regionService->defaultSpApiRegion())));
            $marketplaces = $this->syncFromOfficialApi($resolvedRegion);
        }

        if ($marketplaces->isEmpty()) {
            $regionService = $this->regionConfigService ?? new RegionConfigService();
            return array_values(array_filter((array) ($regionService->spApiConfig($region)['marketplace_ids'] ?? [])));
        }

        return $marketplaces->pluck('id')->all();
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

    public function syncFromOfficialApi(?string $region = null)
    {
        try {
            $regionService = $this->regionConfigService ?? new RegionConfigService();
            $resolvedRegion = strtoupper(trim((string) ($region ?: $regionService->defaultSpApiRegion())));
            if (!in_array($resolvedRegion, ['EU', 'NA', 'FE'], true)) {
                $resolvedRegion = $regionService->defaultSpApiRegion();
            }

            $officialService = $this->officialSpApiService ?? new OfficialSpApiService($regionService);
            $sellersApi = $officialService->makeSellersV1Api($resolvedRegion);
            if ($sellersApi === null) {
                Log::warning('Marketplace sync skipped: official sellers API client unavailable', [
                    'region' => $resolvedRegion,
                ]);
                return collect();
            }

            [$response, $statusCode] = $sellersApi->getMarketplaceParticipationsWithHttpInfo();
            if ($statusCode >= 400) {
                Log::warning('Marketplace sync failed (official SDK)', [
                    'region' => $resolvedRegion,
                    'status' => $statusCode,
                ]);
                return collect();
            }

            $rows = [];
            $payload = is_array($response->getPayload()) ? $response->getPayload() : [];
            foreach ($payload as $participation) {
                if (!is_object($participation) || !method_exists($participation, 'getMarketplace')) {
                    continue;
                }
                $marketplace = $participation->getMarketplace();
                if (!is_object($marketplace)) {
                    continue;
                }

                $id = method_exists($marketplace, 'getId') ? $marketplace->getId() : null;
                if (!is_string($id) || trim($id) === '') {
                    continue;
                }

                $rows[] = [
                    'id' => $id,
                    'name' => method_exists($marketplace, 'getName') ? $marketplace->getName() : null,
                    'country_code' => method_exists($marketplace, 'getCountryCode') ? $marketplace->getCountryCode() : null,
                    'default_currency' => method_exists($marketplace, 'getDefaultCurrencyCode') ? $marketplace->getDefaultCurrencyCode() : null,
                    'default_language' => method_exists($marketplace, 'getDefaultLanguageCode') ? $marketplace->getDefaultLanguageCode() : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($rows)) {
                Marketplace::upsert(
                    $rows,
                    ['id'],
                    ['name', 'country_code', 'default_currency', 'default_language', 'updated_at']
                );
            }

            return Marketplace::query()->orderBy('id')->get();
        } catch (\Throwable $e) {
            Log::warning('Marketplace sync exception (official SDK)', ['error' => $e->getMessage()]);
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
