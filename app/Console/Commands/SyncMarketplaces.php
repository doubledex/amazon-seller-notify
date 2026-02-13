<?php

namespace App\Console\Commands;

use App\Services\MarketplaceService;
use Illuminate\Console\Command;
use SellingPartnerApi\Enums\Endpoint;
use SellingPartnerApi\SellingPartnerApi;

class SyncMarketplaces extends Command
{
    protected $signature = 'marketplaces:sync';
    protected $description = 'Sync Amazon marketplace participation data from SP-API.';

    public function handle(): int
    {
        $endpointValue = strtoupper((string) config('services.amazon_sp_api.endpoint', 'EU'));
        $endpoint = Endpoint::tryFrom($endpointValue) ?? Endpoint::EU;

        $connector = SellingPartnerApi::seller(
            clientId: config('services.amazon_sp_api.client_id'),
            clientSecret: config('services.amazon_sp_api.client_secret'),
            refreshToken: config('services.amazon_sp_api.refresh_token'),
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
