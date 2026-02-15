<?php

namespace App\Console\Commands;

use App\Services\MarketplaceService;
use App\Services\RegionConfigService;
use Illuminate\Console\Command;
use SellingPartnerApi\Enums\Endpoint;
use SellingPartnerApi\SellingPartnerApi;

class SyncMarketplaces extends Command
{
    protected $signature = 'marketplaces:sync {--region= : Optional SP-API region (EU|NA|FE)}';
    protected $description = 'Sync Amazon marketplace participation data from SP-API.';

    public function handle(): int
    {
        $regionConfig = (new RegionConfigService())->spApiConfig((string) ($this->option('region') ?? ''));
        $endpoint = Endpoint::tryFrom((string) $regionConfig['endpoint']) ?? Endpoint::EU;

        $connector = SellingPartnerApi::seller(
            clientId: (string) $regionConfig['client_id'],
            clientSecret: (string) $regionConfig['client_secret'],
            refreshToken: (string) $regionConfig['refresh_token'],
            endpoint: $endpoint
        );

        $service = new MarketplaceService();
        $marketplaces = $service->syncFromApi($connector);

        if ($marketplaces->isEmpty()) {
            $this->warn('Marketplace sync returned no results.');
            return Command::FAILURE;
        }

        $this->info('Marketplace sync completed: ' . $marketplaces->count() . ' marketplaces.');
        return Command::SUCCESS;
    }
}
