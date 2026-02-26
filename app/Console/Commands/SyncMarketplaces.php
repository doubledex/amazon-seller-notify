<?php

namespace App\Console\Commands;

use App\Services\MarketplaceService;
use App\Services\RegionConfigService;
use Illuminate\Console\Command;
use SellingPartnerApi\SellingPartnerApi;

class SyncMarketplaces extends Command
{
    protected $signature = 'marketplaces:sync {--region= : Optional SP-API region (EU|NA|FE)}';
    protected $description = 'Sync Amazon marketplace participation data from SP-API (all configured regions by default).';

    public function handle(): int
    {
        $regionService = new RegionConfigService();
        $regionOption = strtoupper(trim((string) ($this->option('region') ?? '')));
        $regions = $regionOption !== '' ? [$regionOption] : $regionService->spApiRegions();

        $validRegions = ['EU', 'NA', 'FE'];
        $regions = array_values(array_filter(
            $regions,
            static fn (string $region): bool => in_array(strtoupper(trim($region)), $validRegions, true)
        ));

        $service = new MarketplaceService();
        $succeededRegions = [];

        foreach ($regions as $region) {
            $region = strtoupper(trim($region));
            $regionConfig = $regionService->spApiConfig($region);
            $endpoint = $regionService->spApiEndpointEnum($region);

            $connector = SellingPartnerApi::seller(
                clientId: (string) $regionConfig['client_id'],
                clientSecret: (string) $regionConfig['client_secret'],
                refreshToken: (string) $regionConfig['refresh_token'],
                endpoint: $endpoint
            );

            $marketplaces = $service->syncFromApi($connector);
            if ($marketplaces->isEmpty()) {
                $this->warn("[{$region}] Marketplace sync returned no results.");
                continue;
            }

            $succeededRegions[] = $region;
            $this->info("[{$region}] Marketplace sync completed.");
        }

        if (empty($succeededRegions)) {
            return Command::FAILURE;
        }

        $this->info('Marketplace sync completed for region(s): ' . implode(', ', $succeededRegions) . '.');
        return Command::SUCCESS;
    }
}
