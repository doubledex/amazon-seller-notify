<?php

namespace App\Services\Amazon;

use App\Services\RegionConfigService;
use GuzzleHttp\Client as GuzzleClient;
use SpApi\Api\fulfillment\inbound\v0\FbaInboundApi;
use SpApi\Api\notifications\v1\NotificationsApi;
use SpApi\Api\sellerWallet\v2024_03_01\AccountsApi as SellerWalletAccountsApi;
use SpApi\AuthAndAuth\LWAAuthorizationCredentials;
use SpApi\Configuration as OfficialSpApiConfiguration;

class OfficialSpApiService
{
    public function __construct(
        private readonly RegionConfigService $regionConfigService,
    ) {
    }

    public function makeInboundV0Api(string $region): ?FbaInboundApi
    {
        $region = strtoupper(trim($region));
        $officialConfig = $this->makeOfficialConfiguration($region);
        if ($officialConfig === null) {
            return null;
        }

        return new FbaInboundApi($officialConfig, new GuzzleClient());
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
