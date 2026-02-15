<?php

namespace App\Services;

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
        $region = $this->normalizeRegion((string) config('services.amazon_sp_api.endpoint', 'EU'));
        return $region ?? 'EU';
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

    private function normalizeMarketplaceIds(array $marketplaceIds): array
    {
        return array_values(array_filter(array_map(static fn ($id) => trim((string) $id), $marketplaceIds)));
    }
}
