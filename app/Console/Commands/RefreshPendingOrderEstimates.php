<?php

namespace App\Console\Commands;

use App\Services\PendingOrderEstimateService;
use Illuminate\Console\Command;

class RefreshPendingOrderEstimates extends Command
{
    protected $signature = 'orders:refresh-estimates
        {--days=14 : Lookback window in days}
        {--limit=300 : Max order items to consider per run}
        {--max-lookups=80 : Max unique ASIN/marketplace lookups per run}
        {--stale-minutes=180 : Re-price only when estimate is older than this}
        {--region= : Optional region filter (EU|NA|FE)}';

    protected $description = 'Refresh estimated line net ex-tax values for pending/unshipped items using SP-API pricing.';

    public function handle(PendingOrderEstimateService $service): int
    {
        $stats = $service->refresh(
            days: (int) $this->option('days'),
            limit: (int) $this->option('limit'),
            maxLookups: (int) $this->option('max-lookups'),
            staleMinutes: (int) $this->option('stale-minutes'),
            region: $this->option('region') ? (string) $this->option('region') : null,
        );

        $this->info(sprintf(
            'Pending estimates refreshed: considered=%d updated=%d lookup_keys=%d api_calls=%d api_success=%d cache_hits=%d cache_misses=%d skipped_no_price=%d',
            (int) ($stats['considered'] ?? 0),
            (int) ($stats['updated'] ?? 0),
            (int) ($stats['lookup_keys'] ?? 0),
            (int) ($stats['api_calls'] ?? 0),
            (int) ($stats['api_success'] ?? 0),
            (int) ($stats['cache_hits'] ?? 0),
            (int) ($stats['cache_misses'] ?? 0),
            (int) ($stats['skipped_no_price'] ?? 0),
        ));

        return Command::SUCCESS;
    }
}
