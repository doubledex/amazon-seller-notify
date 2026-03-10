<?php

namespace App\Services\Amazon\Orders;

use App\Services\Amazon\OfficialSpApiService;
use App\Services\MarketplaceService;
use App\Services\RegionConfigService;

/**
 * @deprecated Use OfficialOrderAdapter. Kept as compatibility alias.
 */
class LegacyOrderAdapter extends OfficialOrderAdapter
{
    public function __construct(
        OfficialSpApiService $officialSpApiService,
        MarketplaceService $marketplaceService,
        RegionConfigService $regionConfigService,
    ) {
        parent::__construct($officialSpApiService, $marketplaceService, $regionConfigService);
    }
}
