<?php

namespace App\Services;

class RegionConfigService
{
    private const VALID_REGIONS = ['EU', 'NA', 'FE'];
    private const ENDPOINT_BY_REGION = [
        'NA' => 'https://sellingpartnerapi-na.amazon.com',
        'EU' => 'https://sellingpartnerapi-eu.amazon.com',
        'FE' => 'https://sellingpartnerapi-fe.amazon.com',
    ];
    private const SANDBOX_ENDPOINT_BY_REGION = [
        'NA' => 'https://sandbox.sellingpartnerapi-na.amazon.com',
        'EU' => 'https://sandbox.sellingpartnerapi-eu.amazon.com',
        'FE' => 'https://sandbox.sellingpartnerapi-fe.amazon.com',
    ];

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

    public function spApiEndpoint(?string $region = null): string
    {
        $config = $this->spApiConfig($region);
        $endpointRaw = trim((string) ($config['endpoint'] ?? ''));

        return $this->normalizeEndpointOrRegionToEndpoint($endpointRaw)
            ?? $this->normalizeEndpointOrRegionToEndpoint((string) ($config['region'] ?? 'EU'))
            ?? self::ENDPOINT_BY_REGION['EU'];
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
        $value = strtoupper(trim($value));
        if ($value === '') {
            return null;
        }

        $region = $this->normalizeRegion($value);
        if ($region !== null) {
            return $region;
        }

        return match ($value) {
            'NA_SANDBOX', strtoupper(self::SANDBOX_ENDPOINT_BY_REGION['NA']), strtoupper(self::ENDPOINT_BY_REGION['NA']) => 'NA',
            'EU_SANDBOX', strtoupper(self::SANDBOX_ENDPOINT_BY_REGION['EU']), strtoupper(self::ENDPOINT_BY_REGION['EU']) => 'EU',
            'FE_SANDBOX', strtoupper(self::SANDBOX_ENDPOINT_BY_REGION['FE']), strtoupper(self::ENDPOINT_BY_REGION['FE']) => 'FE',
            default => null,
        };
    }

    private function normalizeEndpointOrRegionToEndpoint(string $value): ?string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return null;
        }

        $region = $this->normalizeEndpointOrRegionToRegion($value);
        if ($region === null) {
            return null;
        }

        if (str_ends_with($value, '_SANDBOX') || str_contains($value, 'SANDBOX.')) {
            return self::SANDBOX_ENDPOINT_BY_REGION[$region];
        }

        return self::ENDPOINT_BY_REGION[$region];
    }

    private function normalizeMarketplaceIds(array $marketplaceIds): array
    {
        return array_values(array_filter(array_map(static fn ($id) => trim((string) $id), $marketplaceIds)));
    }
}
