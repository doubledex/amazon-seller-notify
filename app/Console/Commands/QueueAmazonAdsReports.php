<?php

namespace App\Console\Commands;

use App\Services\AmazonAdsSpendSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class QueueAmazonAdsReports extends Command
{
    protected $signature = 'ads:queue-reports {--from=} {--to=} {--profile-id=*} {--max-profiles=} {--ad-product=* : SPONSORED_PRODUCTS|SPONSORED_BRANDS|SPONSORED_DISPLAY}';
    protected $description = 'Queue Amazon Ads report requests for later background processing.';

    public function handle(AmazonAdsSpendSyncService $adsService): int
    {
        $from = $this->option('from') ? Carbon::parse((string) $this->option('from')) : now()->subDay();
        $to = $this->option('to') ? Carbon::parse((string) $this->option('to')) : $from;
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $profileIds = array_values(array_filter(array_map('strval', (array) $this->option('profile-id'))));
        $maxProfiles = $this->option('max-profiles') !== null ? (int) $this->option('max-profiles') : null;
        $adProducts = array_values(array_filter(array_map(fn ($v) => strtoupper(trim((string) $v)), (array) $this->option('ad-product'))));

        $result = $adsService->queueRangeReports($from, $to, $profileIds, $maxProfiles, $adProducts ?: null);
        if (!$result['ok']) {
            $this->error((string) $result['message']);
            return self::FAILURE;
        }

        $this->info((string) $result['message']);
        $this->line('Created: ' . (int) $result['created']);
        $this->line('Existing: ' . (int) $result['existing']);
        $this->line('Failed: ' . (int) $result['failed']);
        $this->line('Outstanding: ' . (int) $result['outstanding']);
        $this->line('Oldest wait (s): ' . (int) $result['oldest_wait_seconds']);

        return self::SUCCESS;
    }
}
