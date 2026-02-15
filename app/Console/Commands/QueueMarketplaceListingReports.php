<?php

namespace App\Console\Commands;

use App\Services\MarketplaceListingsSyncService;
use Illuminate\Console\Command;

class QueueMarketplaceListingReports extends Command
{
    protected $signature = 'listings:queue-reports {--marketplace=* : Optional marketplace IDs to sync} {--report-type=GET_MERCHANT_LISTINGS_ALL_DATA : SP-API report type} {--region= : Optional SP-API region (EU|NA|FE)}';
    protected $description = 'Queue SP-API listing reports for background polling.';

    public function handle(MarketplaceListingsSyncService $service): int
    {
        $marketplaces = array_values(array_filter(array_map('strval', (array) $this->option('marketplace'))));
        $reportType = trim((string) $this->option('report-type'));
        $region = $this->option('region') ? (string) $this->option('region') : null;

        $result = $service->queueEuropeReports(
            $marketplaces ?: null,
            $reportType !== '' ? $reportType : MarketplaceListingsSyncService::DEFAULT_REPORT_TYPE,
            $region
        );

        $this->info('SP-API listings report queueing complete.');
        $this->line('Created: ' . (int) ($result['created'] ?? 0));
        $this->line('Existing: ' . (int) ($result['existing'] ?? 0));
        $this->line('Failed: ' . (int) ($result['failed'] ?? 0));
        $this->line('Outstanding: ' . (int) ($result['outstanding'] ?? 0));

        foreach (($result['marketplaces'] ?? []) as $marketplaceId => $marketplaceResult) {
            if (!empty($marketplaceResult['error'])) {
                $this->warn("{$marketplaceId}: error - {$marketplaceResult['error']}");
            }
        }

        return self::SUCCESS;
    }
}
