<?php

namespace App\Console\Commands;

use App\Services\AmazonAdsSpendSyncService;
use App\Services\DailyRegionMetricsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncAmazonAdsSpend extends Command
{
    protected $signature = 'ads:sync-spend {--from=} {--to=} {--refresh-metrics=1} {--profile-id=*} {--max-profiles=} {--poll-attempts=60} {--ad-product=* : SPONSORED_PRODUCTS|SPONSORED_BRANDS|SPONSORED_DISPLAY}';
    protected $description = 'Sync Amazon Ads daily spend for UK/EU and optionally refresh daily metrics.';

    public function handle(AmazonAdsSpendSyncService $adsService, DailyRegionMetricsService $metricsService): int
    {
        $from = $this->option('from') ? Carbon::parse((string) $this->option('from')) : now()->subDay();
        $to = $this->option('to') ? Carbon::parse((string) $this->option('to')) : $from;
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $profileIds = array_values(array_filter(array_map('strval', (array) $this->option('profile-id'))));
        $maxProfiles = $this->option('max-profiles') !== null ? (int) $this->option('max-profiles') : null;
        $pollAttempts = max(1, (int) $this->option('poll-attempts'));
        $adProducts = array_values(array_filter(array_map(fn ($v) => strtoupper(trim((string) $v)), (array) $this->option('ad-product'))));

        $result = $adsService->syncRange($from, $to, $profileIds, $maxProfiles, $pollAttempts, $adProducts ?: null);
        if (!$result['ok']) {
            $this->error($result['message']);
            return self::FAILURE;
        }

        $this->info($result['message'] . ' Rows upserted: ' . (int) $result['rows']);
        foreach (($result['rows_by_profile'] ?? []) as $profileId => $count) {
            $this->line("Profile {$profileId}: {$count} daily rows");
        }

        if ((string) $this->option('refresh-metrics') !== '0') {
            $summary = $metricsService->refreshRange($from, $to);
            $this->info("Metrics refreshed for {$summary['days']} day(s), {$summary['regions']} region(s).");
        }

        return self::SUCCESS;
    }
}
