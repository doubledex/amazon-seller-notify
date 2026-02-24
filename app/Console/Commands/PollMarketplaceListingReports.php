<?php

namespace App\Console\Commands;

use App\Services\ReportJobOrchestrator;
use Illuminate\Console\Command;

class PollMarketplaceListingReports extends Command
{
    protected $signature = 'listings:poll-reports {--limit=100} {--marketplace=* : Optional marketplace IDs} {--report-type=GET_MERCHANT_LISTINGS_ALL_DATA : SP-API report type} {--region= : Optional SP-API region (EU|NA|FE)}';
    protected $description = 'Poll queued SP-API listing reports and ingest completed report documents.';

    public function handle(ReportJobOrchestrator $orchestrator): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $marketplaces = array_values(array_filter(array_map('strval', (array) $this->option('marketplace'))));
        $reportType = trim((string) $this->option('report-type'));
        $region = $this->option('region') ? (string) $this->option('region') : null;

        $result = $orchestrator->pollSpApiSellerJobs(
            $limit,
            'marketplace_listings',
            $region,
            $marketplaces ?: null,
            $reportType !== '' ? $reportType : null
        );

        $this->info('SP-API listings report polling complete.');
        $this->line('Checked: ' . (int) ($result['checked'] ?? 0));
        $this->line('Processed: ' . (int) ($result['processed'] ?? 0));
        $this->line('Failed: ' . (int) ($result['failed'] ?? 0));
        $this->line('Outstanding: ' . (int) ($result['outstanding'] ?? 0));

        return self::SUCCESS;
    }
}
