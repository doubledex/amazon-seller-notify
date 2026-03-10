<?php

namespace App\Integrations\Amazon\SpApi;

use App\Services\Amazon\OfficialSpApiService;
use App\Services\RegionConfigService;
use SpApi\Api\finances\v2024_06_19\DefaultApi as FinancesV20240619Api;
use SpApi\Api\fulfillment\inbound\v0\FbaInboundApi;
use SpApi\Api\notifications\v1\NotificationsApi;
use SpApi\Api\orders\v2026_01_01\GetOrderApi as OrdersV20260101GetOrderApi;
use SpApi\Api\orders\v2026_01_01\SearchOrdersApi as OrdersV20260101SearchOrdersApi;
use SpApi\Api\orders\v0\OrdersV0Api;
use SpApi\Api\sellerWallet\v2024_03_01\AccountsApi as SellerWalletAccountsApi;

class SpApiClientFactory
{
    public function __construct(
        private readonly ?RegionConfigService $regionConfigService = null,
        private readonly ?OfficialSpApiService $officialSpApiService = null
    ) {
    }

    public function makeInboundV0Api(?string $region = null): ?FbaInboundApi
    {
        return $this->officialSpApiService()->makeInboundV0Api($this->resolveRegion($region));
    }

    public function makeNotificationsV1Api(?string $region = null): ?NotificationsApi
    {
        return $this->officialSpApiService()->makeNotificationsV1Api($this->resolveRegion($region));
    }

    public function makeSellerWalletAccountsApi(?string $region = null): ?SellerWalletAccountsApi
    {
        return $this->officialSpApiService()->makeSellerWalletAccountsApi($this->resolveRegion($region));
    }

    public function makeOrdersV0Api(?string $region = null): ?OrdersV0Api
    {
        return $this->officialSpApiService()->makeOrdersV0Api($this->resolveRegion($region));
    }

    public function makeSearchOrdersV20260101Api(?string $region = null): ?OrdersV20260101SearchOrdersApi
    {
        return $this->officialSpApiService()->makeSearchOrdersV20260101Api($this->resolveRegion($region));
    }

    public function makeGetOrderV20260101Api(?string $region = null): ?OrdersV20260101GetOrderApi
    {
        return $this->officialSpApiService()->makeGetOrderV20260101Api($this->resolveRegion($region));
    }

    public function makeFinancesV20240619Api(?string $region = null): ?FinancesV20240619Api
    {
        return $this->officialSpApiService()->makeFinancesV20240619Api($this->resolveRegion($region));
    }

    private function resolveRegion(?string $region): string
    {
        $normalized = strtoupper(trim((string) $region));
        if (in_array($normalized, ['EU', 'NA', 'FE'], true)) {
            return $normalized;
        }

        return $this->regionConfigService()->defaultSpApiRegion();
    }

    private function regionConfigService(): RegionConfigService
    {
        return $this->regionConfigService ?? new RegionConfigService();
    }

    private function officialSpApiService(): OfficialSpApiService
    {
        return $this->officialSpApiService ?? new OfficialSpApiService($this->regionConfigService());
    }
}
