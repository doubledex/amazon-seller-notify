<?php

namespace App\Services;

use SellingPartnerApi\Enums\Endpoint;
use SellingPartnerApi\Enums\Region;

class RegionConfigService
{
    private const VALID_REGIONS = ['EU', 'NA', 'FE'];

    public function spApiRegions(): array
    {
        $configured = $this->normalizeRegions(config('services.amazon_sp_api.regions', []));
        if (!empty($configured)) {
            return $configured;
        }

        return [$this->defaultSpApiRegion()];
    }

    public function spApiConfig(?string $region = null): array
    {
        $region = $this->normalizeRegion($region) ?? $this->defaultSpApiRegion();
        $legacy = (array) config('services.amazon_sp_api', []);
        $byRegion = (array) (($legacy['by_region'] ?? [])[$region] ?? []);

        $marketplaceIds = $this->normalizeMarketplaceIds($byRegion['marketplace_ids'] ?? []);
        if (empty($marketplaceIds)) {
            $marketplaceIds = $this->normalizeMarketplaceIds($legacy['marketplace_ids'] ?? []);
        }

        return [
            'region' => $region,
            'endpoint' => strtoupper((string) ($byRegion['endpoint'] ?? $legacy['endpoint'] ?? $region)),
            'client_id' => (string) ($byRegion['client_id'] ?? $legacy['client_id'] ?? ''),
            'client_secret' => (string) ($byRegion['client_secret'] ?? $legacy['client_secret'] ?? ''),
            'refresh_token' => (string) ($byRegion['refresh_token'] ?? $legacy['refresh_token'] ?? ''),
            'application_id' => (string) ($byRegion['application_id'] ?? $legacy['application_id'] ?? ''),
            'marketplace_ids' => $marketplaceIds,
        ];
    }

    public function adsRegions(): array
    {
        $configured = $this->normalizeRegions(config('services.amazon_ads.regions', []));
        if (!empty($configured)) {
            return $configured;
        }

        return [$this->defaultAdsRegion()];
    }

    public function adsConfig(?string $region = null): array
    {
        $region = $this->normalizeRegion($region) ?? $this->defaultAdsRegion();
        $legacy = (array) config('services.amazon_ads', []);
        $byRegion = (array) (($legacy['by_region'] ?? [])[$region] ?? []);

        return [
            'region' => $region,
            'client_id' => (string) ($byRegion['client_id'] ?? $legacy['client_id'] ?? ''),
            'client_secret' => (string) ($byRegion['client_secret'] ?? $legacy['client_secret'] ?? ''),
            'refresh_token' => (string) ($byRegion['refresh_token'] ?? $legacy['refresh_token'] ?? ''),
            'base_url' => (string) ($byRegion['base_url'] ?? $legacy['base_url'] ?? ''),
        ];
    }

    public function defaultSpApiRegion(): string
    {
        $region = $this->normalizeEndpointOrRegionToRegion((string) config('services.amazon_sp_api.endpoint', 'EU'));
        return $region ?? 'EU';
    }

    public function spApiEndpointEnum(?string $region = null): Endpoint
    {
        $config = $this->spApiConfig($region);
        $endpointRaw = trim((string) ($config['endpoint'] ?? ''));

        $normalizedRegion = $this->normalizeEndpointOrRegionToRegion($endpointRaw)
            ?? $this->normalizeEndpointOrRegionToRegion((string) ($config['region'] ?? 'EU'))
            ?? 'EU';

        return Endpoint::byRegion(Region::from($normalizedRegion));
    }

    public function defaultAdsRegion(): string
    {
        $region = $this->normalizeRegion((string) config('services.amazon_ads.default_region', 'EU'));
        return $region ?? 'EU';
    }

    private function normalizeRegions(array $regions): array
    {
        $normalized = [];
        foreach ($regions as $region) {
            $clean = $this->normalizeRegion((string) $region);
            if ($clean !== null) {
                $normalized[$clean] = true;
            }
        }

        return array_keys($normalized);
    }

    private function normalizeRegion(?string $region): ?string
    {
        $region = strtoupper(trim((string) $region));
        return in_array($region, self::VALID_REGIONS, true) ? $region : null;
    }

    private function normalizeEndpointOrRegionToRegion(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $region = $this->normalizeRegion($value);
        if ($region !== null) {
            return $region;
        }

        $endpoint = Endpoint::tryFrom($value);
        if ($endpoint === null) {
            return null;
        }

        return match ($endpoint) {
            Endpoint::NA, Endpoint::NA_SANDBOX => 'NA',
            Endpoint::EU, Endpoint::EU_SANDBOX => 'EU',
            Endpoint::FE, Endpoint::FE_SANDBOX => 'FE',
        };
    }

    private function normalizeMarketplaceIds(array $marketplaceIds): array
    {
        return array_values(array_filter(array_map(static fn ($id) => trim((string) $id), $marketplaceIds)));
    }
}
