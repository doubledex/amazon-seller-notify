<?php

namespace App\Console\Commands;

use App\Services\AmazonAdsSpendSyncService;
use App\Services\DailyRegionMetricsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PollAmazonAdsReports extends Command
{
    protected $signature = 'ads:poll-reports {--limit=100} {--refresh-metrics=0}';
    protected $description = 'Poll outstanding Amazon Ads report requests and ingest completed results.';

    public function handle(AmazonAdsSpendSyncService $adsService, DailyRegionMetricsService $metricsService): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $result = $adsService->processPendingReports($limit);
        if (!$result['ok']) {
            $this->error((string) $result['message']);
            return self::FAILURE;
        }

        $this->info((string) $result['message']);
        $this->line('Checked: ' . (int) $result['checked']);
        $this->line('Completed: ' . (int) $result['completed']);
        $this->line('Processed: ' . (int) $result['processed']);
        $this->line('Failed: ' . (int) $result['failed']);
        $this->line('Aggregated rows: ' . (int) $result['aggregated_rows']);
        $this->line('Outstanding: ' . (int) $result['outstanding']);
        $this->line('Oldest wait (s): ' . (int) $result['oldest_wait_seconds']);

        if ((string) $this->option('refresh-metrics') !== '0' && (int) $result['processed'] > 0) {
            $affectedDates = array_values(array_filter(array_map('strval', (array) ($result['affected_dates'] ?? []))));
            if (!empty($affectedDates)) {
                $summary = $metricsService->refreshDates($affectedDates);
                $this->line("Metrics refreshed for {$summary['days']} affected day(s), {$summary['regions']} region(s).");
            } else {
                $today = Carbon::today();
                $summary = $metricsService->refreshRange($today->copy()->subDays(2), $today);
                $this->line("Metrics refreshed fallback for {$summary['days']} day(s), {$summary['regions']} region(s).");
            }
        }

        return self::SUCCESS;
    }
}
