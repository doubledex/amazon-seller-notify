<?php

namespace App\Console\Commands;

use App\Services\ReportJobOrchestrator;
use Illuminate\Console\Command;

class QueueMarketplaceListingReports extends Command
{
    protected $signature = 'listings:queue-reports {--marketplace=* : Optional marketplace IDs to sync} {--report-type=GET_MERCHANT_LISTINGS_ALL_DATA : SP-API report type} {--region= : Optional SP-API region (EU|NA|FE)}';
    protected $description = 'Queue SP-API listing reports for background polling.';

    public function handle(ReportJobOrchestrator $orchestrator): int
    {
        $marketplaces = array_values(array_filter(array_map('strval', (array) $this->option('marketplace'))));
        $reportType = trim((string) $this->option('report-type'));
        $region = $this->option('region') ? (string) $this->option('region') : null;

        $result = $orchestrator->queueSpApiSellerJobs(
            $reportType !== '' ? strtoupper($reportType) : 'GET_MERCHANT_LISTINGS_ALL_DATA',
            $marketplaces ?: null,
            $region,
            null,
            'marketplace_listings'
        );

        $this->info('SP-API listings report queueing complete.');
        $this->line('Created: ' . (int) ($result['created'] ?? 0));

        return self::SUCCESS;
    }
}
