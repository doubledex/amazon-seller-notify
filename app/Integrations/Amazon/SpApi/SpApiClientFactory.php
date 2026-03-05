<?php

namespace App\Integrations\Amazon\SpApi;

use App\Services\RegionConfigService;
use SellingPartnerApi\SellingPartnerApi;

class SpApiClientFactory
{
    public function __construct(private readonly ?RegionConfigService $regionConfigService = null)
    {
    }

    public function makeSellerConnector(?string $region = null)
    {
        $regionService = $this->regionConfigService ?? new RegionConfigService();
        $config = $regionService->spApiConfig($region);
        $endpoint = $regionService->spApiEndpointEnum($region);

        return SellingPartnerApi::seller(
            clientId: $config['client_id'],
            clientSecret: $config['client_secret'],
            refreshToken: $config['refresh_token'],
            endpoint: $endpoint
        );
    }
}
