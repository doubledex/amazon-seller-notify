<?php

namespace App\Integrations\Amazon\SpApi;

use App\Services\RegionConfigService;
use SellingPartnerApi\SellingPartnerApi;

class SpApiClientFactory
{
    public function __construct(private readonly RegionConfigService $regionConfigService)
    {
    }

    public function makeSellerConnector(?string $region = null)
    {
        $config = $this->regionConfigService->spApiConfig($region);
        $endpoint = $this->regionConfigService->spApiEndpointEnum($region);

        return SellingPartnerApi::seller(
            clientId: $config['client_id'],
            clientSecret: $config['client_secret'],
            refreshToken: $config['refresh_token'],
            endpoint: $endpoint
        );
    }
}
