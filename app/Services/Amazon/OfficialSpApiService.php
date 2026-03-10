<?php

namespace App\Services\Amazon;

use App\Services\RegionConfigService;
use GuzzleHttp\Client as GuzzleClient;
use SpApi\Api\finances\v2024_06_19\DefaultApi as FinancesV20240619Api;
use SpApi\Api\fulfillment\inbound\v2024_03_20\FbaInboundApi as FbaInboundV20240320Api;
use SpApi\Api\orders\v2026_01_01\GetOrderApi as OrdersV20260101GetOrderApi;
use SpApi\Api\orders\v2026_01_01\SearchOrdersApi as OrdersV20260101SearchOrdersApi;
use SpApi\Api\notifications\v1\NotificationsApi;
use SpApi\Api\pricing\v2022_05_01\ProductPricingApi as ProductPricingV20220501Api;
use SpApi\Api\productFees\v0\FeesApi;
use SpApi\Api\catalogItems\v2022_04_01\CatalogApi;
use SpApi\Api\reports\v2021_06_30\ReportsApi;
use SpApi\Api\sellers\v1\SellersApi;
use SpApi\Api\sellerWallet\v2024_03_01\AccountsApi as SellerWalletAccountsApi;
use SpApi\AuthAndAuth\LWAAuthorizationCredentials;
use SpApi\Configuration as OfficialSpApiConfiguration;

class OfficialSpApiService
{
    public function __construct(
        private readonly RegionConfigService $regionConfigService,
    ) {
    }

    public function makeInboundV20240320Api(string $region): ?FbaInboundV20240320Api
    {
        $region = strtoupper(trim($region));
        $officialConfig = $this->makeOfficialConfiguration($region);
        if ($officialConfig === null) {
            return null;
        }

        return new FbaInboundV20240320Api($officialConfig, new GuzzleClient());
    }

    public function makeSellerWalletAccountsApi(string $region): ?SellerWalletAccountsApi
    {
        $region = strtoupper(trim($region));
        $officialConfig = $this->makeOfficialConfiguration($region);
        if ($officialConfig === null) {
            return null;
        }

        return new SellerWalletAccountsApi($officialConfig, new GuzzleClient());
    }

    public function makeNotificationsV1Api(string $region): ?NotificationsApi
    {
        $region = strtoupper(trim($region));
        $officialConfig = $this->makeOfficialConfiguration($region);
        if ($officialConfig === null) {
            return null;
        }

        return new NotificationsApi($officialConfig, new GuzzleClient());
    }

    public function makeSearchOrdersV20260101Api(string $region): ?OrdersV20260101SearchOrdersApi
    {
        $region = strtoupper(trim($region));
        $officialConfig = $this->makeOfficialConfiguration($region);
        if ($officialConfig === null) {
            return null;
        }

        return new OrdersV20260101SearchOrdersApi($officialConfig, new GuzzleClient());
    }

    public function makeGetOrderV20260101Api(string $region): ?OrdersV20260101GetOrderApi
    {
        $region = strtoupper(trim($region));
        $officialConfig = $this->makeOfficialConfiguration($region);
        if ($officialConfig === null) {
            return null;
        }

        return new OrdersV20260101GetOrderApi($officialConfig, new GuzzleClient());
    }

    public function makeFinancesV20240619Api(string $region): ?FinancesV20240619Api
    {
        $region = strtoupper(trim($region));
        $officialConfig = $this->makeOfficialConfiguration($region);
        if ($officialConfig === null) {
            return null;
        }

        return new FinancesV20240619Api($officialConfig, new GuzzleClient());
    }

    public function makeProductFeesV0Api(string $region): ?FeesApi
    {
        $region = strtoupper(trim($region));
        $officialConfig = $this->makeOfficialConfiguration($region);
        if ($officialConfig === null) {
            return null;
        }

        return new FeesApi($officialConfig, new GuzzleClient());
    }

    public function makePricingV20220501Api(string $region): ?ProductPricingV20220501Api
    {
        $region = strtoupper(trim($region));
        $officialConfig = $this->makeOfficialConfiguration($region);
        if ($officialConfig === null) {
            return null;
        }

        return new ProductPricingV20220501Api($officialConfig, new GuzzleClient());
    }

    public function makeSellersV1Api(string $region): ?SellersApi
    {
        $region = strtoupper(trim($region));
        $officialConfig = $this->makeOfficialConfiguration($region);
        if ($officialConfig === null) {
            return null;
        }

        return new SellersApi($officialConfig, new GuzzleClient());
    }

    public function makeReportsV20210630Api(string $region): ?ReportsApi
    {
        $region = strtoupper(trim($region));
        $officialConfig = $this->makeOfficialConfiguration($region);
        if ($officialConfig === null) {
            return null;
        }

        return new ReportsApi($officialConfig, new GuzzleClient());
    }

    public function makeCatalogItemsV20220401Api(string $region): ?CatalogApi
    {
        $region = strtoupper(trim($region));
        $officialConfig = $this->makeOfficialConfiguration($region);
        if ($officialConfig === null) {
            return null;
        }

        return new CatalogApi($officialConfig, new GuzzleClient());
    }

    private function makeOfficialConfiguration(string $region): ?OfficialSpApiConfiguration
    {
        $config = $this->regionConfigService->spApiConfig($region);
        $host = $this->hostForRegion($region);
        if ($host === null) {
            return null;
        }

        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        $refreshToken = trim((string) ($config['refresh_token'] ?? ''));
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            return null;
        }

        $lwa = new LWAAuthorizationCredentials([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'refreshToken' => $refreshToken,
            'endpoint' => 'https://api.amazon.com/auth/o2/token',
        ]);
        $officialConfig = new OfficialSpApiConfiguration([], $lwa);
        $officialConfig->setHost($host);

        return $officialConfig;
    }

    private function hostForRegion(string $region): ?string
    {
        return match ($region) {
            'EU' => 'https://sellingpartnerapi-eu.amazon.com',
            'NA' => 'https://sellingpartnerapi-na.amazon.com',
            'FE' => 'https://sellingpartnerapi-fe.amazon.com',
            default => null,
        };
    }
}
