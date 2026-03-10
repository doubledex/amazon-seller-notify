<?php

namespace App\Support\Amazon;

use SellingPartnerApi\Enums\Endpoint;
use SellingPartnerApi\Enums\Region;

class LegacySpApiEndpointResolver
{
    public static function fromEndpointOrRegion(string $value): Endpoint
    {
        $normalized = strtoupper(trim($value));

        return match ($normalized) {
            'NA' => Endpoint::byRegion(Region::NA),
            'EU' => Endpoint::byRegion(Region::EU),
            'FE' => Endpoint::byRegion(Region::FE),
            'NA_SANDBOX' => Endpoint::NA_SANDBOX,
            'EU_SANDBOX' => Endpoint::EU_SANDBOX,
            'FE_SANDBOX' => Endpoint::FE_SANDBOX,
            'HTTPS://SELLINGPARTNERAPI-NA.AMAZON.COM' => Endpoint::NA,
            'HTTPS://SELLINGPARTNERAPI-EU.AMAZON.COM' => Endpoint::EU,
            'HTTPS://SELLINGPARTNERAPI-FE.AMAZON.COM' => Endpoint::FE,
            'HTTPS://SANDBOX.SELLINGPARTNERAPI-NA.AMAZON.COM' => Endpoint::NA_SANDBOX,
            'HTTPS://SANDBOX.SELLINGPARTNERAPI-EU.AMAZON.COM' => Endpoint::EU_SANDBOX,
            'HTTPS://SANDBOX.SELLINGPARTNERAPI-FE.AMAZON.COM' => Endpoint::FE_SANDBOX,
            default => Endpoint::byRegion(Region::EU),
        };
    }
}
