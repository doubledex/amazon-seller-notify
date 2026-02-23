<?php

namespace App\Console\Commands;

use App\Services\OrderFeeEstimateService;
use Illuminate\Console\Command;

class RefreshOrderFeeEstimates extends Command
{
    protected $signature = 'orders:refresh-fee-estimates
        {--days=14 : Lookback window in days}
        {--limit=300 : Max order items to consider per run}
        {--max-lookups=120 : Max unique ASIN/marketplace/price lookups per run}
        {--stale-minutes=360 : Re-estimate only when estimate is older than this}
        {--region= : Optional region filter (EU|NA|FE)}';

    protected $description = 'Refresh estimated order fees when Finances fee events are missing.';

    public function handle(OrderFeeEstimateService $service): int
    {
        $stats = $service->refresh(
            days: (int) $this->option('days'),
            limit: (int) $this->option('limit'),
            maxLookups: (int) $this->option('max-lookups'),
            staleMinutes: (int) $this->option('stale-minutes'),
            region: $this->option('region') ? (string) $this->option('region') : null,
        );

        $this->info(sprintf(
            'Fee estimates refreshed: considered=%d orders_updated=%d lookup_keys=%d api_calls=%d api_success=%d cache_hits=%d cache_misses=%d skipped_no_basis=%d',
            (int) ($stats['considered'] ?? 0),
            (int) ($stats['orders_updated'] ?? 0),
            (int) ($stats['lookup_keys'] ?? 0),
            (int) ($stats['api_calls'] ?? 0),
            (int) ($stats['api_success'] ?? 0),
            (int) ($stats['cache_hits'] ?? 0),
            (int) ($stats['cache_misses'] ?? 0),
            (int) ($stats['skipped_no_basis'] ?? 0),
        ));

        return Command::SUCCESS;
    }
}

