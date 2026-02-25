<?php

namespace App\Integrations\Amazon\SpApi;

use SellingPartnerApi\Enums\Endpoint;
use SellingPartnerApi\SellingPartnerApi;

class SpApiClientFactory
{
    public function makeSellerConnector()
    {
        $endpointValue = strtoupper((string) config('services.amazon_sp_api.endpoint', 'EU'));
        $endpoint = Endpoint::tryFrom($endpointValue) ?? Endpoint::EU;

        return SellingPartnerApi::seller(
            clientId: config('services.amazon_sp_api.client_id'),
            clientSecret: config('services.amazon_sp_api.client_secret'),
            refreshToken: config('services.amazon_sp_api.refresh_token'),
            endpoint: $endpoint
        );
    }
}
