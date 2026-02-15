<?php

namespace App\Console\Commands;

use App\Services\DailyRegionMetricsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RefreshDailyRegionMetrics extends Command
{
    protected $signature = 'metrics:refresh {--from=} {--to=} {--ad-spend-csv=}';
    protected $description = 'Refresh UK/EU daily sales + ad spend metrics with GBP conversion and ACOS.';

    public function handle(DailyRegionMetricsService $service): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $csv = $this->option('ad-spend-csv');

        if ($csv) {
            $count = $service->importAdSpendCsv((string) $csv);
            $this->info("Imported ad spend rows: {$count}");
        }

        $fromDate = $from ? Carbon::parse((string) $from) : now()->subDay();
        $toDate = $to ? Carbon::parse((string) $to) : $fromDate;
        if ($toDate->lt($fromDate)) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        $summary = $service->refreshRange($fromDate, $toDate);
        $this->info("Metrics refreshed for {$summary['days']} day(s), {$summary['regions']} region(s).");

        return self::SUCCESS;
    }
}

