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
        $regionConfigService = $this->regionConfigService ?? new RegionConfigService();
        $config = $regionConfigService->spApiConfig($region);
        $endpoint = $regionConfigService->spApiEndpointEnum($region);

        return SellingPartnerApi::seller(
            clientId: $config['client_id'],
            clientSecret: $config['client_secret'],
            refreshToken: $config['refresh_token'],
            endpoint: $endpoint
        );
    }
}
