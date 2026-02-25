<?php

namespace App\Console\Commands;

use App\Services\MarketplaceListingsSyncService;
use Illuminate\Console\Command;

class SyncMarketplaceListings extends Command
{
    protected $signature = 'listings:sync-europe {--marketplace=* : Optional marketplace IDs to sync} {--max-attempts=8 : Poll attempts per marketplace} {--sleep-seconds=3 : Seconds between poll attempts} {--report-type=GET_MERCHANT_LISTINGS_ALL_DATA : SP-API listings report type} {--region= : Optional SP-API region (EU|NA|FE)}';
    protected $description = 'Sync marketplace listings for Europe using SP-API reports.';

    public function handle(MarketplaceListingsSyncService $service): int
    {
        $this->info('Starting Europe listings sync...');
        $marketplaces = (array) $this->option('marketplace');
        $marketplaces = array_values(array_filter(array_map('strval', $marketplaces)));
        $maxAttempts = max(1, (int) $this->option('max-attempts'));
        $sleepSeconds = max(1, (int) $this->option('sleep-seconds'));
        $reportType = trim((string) $this->option('report-type'));
        $region = $this->option('region') ? (string) $this->option('region') : null;

        $result = $service->syncEurope(
            $marketplaces ?: null,
            $maxAttempts,
            $sleepSeconds,
            $reportType !== '' ? $reportType : MarketplaceListingsSyncService::DEFAULT_REPORT_TYPE,
            $region
        );

        $this->info('Total listings synced: ' . (int) ($result['synced'] ?? 0));

        foreach (($result['marketplaces'] ?? []) as $marketplaceId => $marketplaceResult) {
            if (!empty($marketplaceResult['error'])) {
                $this->warn("{$marketplaceId}: error - {$marketplaceResult['error']}");
                continue;
            }
            $parentInfo = isset($marketplaceResult['parents']) ? ", parent ASINs {$marketplaceResult['parents']}" : '';
            $this->line("{$marketplaceId}: synced {$marketplaceResult['synced']}{$parentInfo}");
        }

        return self::SUCCESS;
    }
}
