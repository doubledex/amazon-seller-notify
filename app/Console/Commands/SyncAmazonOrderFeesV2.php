<?php

namespace App\Console\Commands;

use App\Services\AmazonOrderFeeSyncV2Service;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncAmazonOrderFeesV2 extends Command
{
    protected $signature = 'fees:sync-v2 {--from=} {--to=} {--days=7} {--region= : Optional SP-API region (EU|NA|FE)}';
    protected $description = 'Sync Amazon order fees using Finances v2024-06-19 into v2 tables.';

    public function handle(AmazonOrderFeeSyncV2Service $service): int
    {
        $from = $this->option('from')
            ? Carbon::parse((string) $this->option('from'))
            : now()->subDays((int) $this->option('days'));
        $to = $this->option('to')
            ? Carbon::parse((string) $this->option('to'))
            : now();

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $summary = $service->sync($from, $to, $this->option('region') ? (string) $this->option('region') : null);

        $this->info(sprintf(
            'Fee sync v2 complete. Regions: %d, Transactions scanned: %d, Orders touched: %d, Orders updated: %d.',
            (int) ($summary['regions'] ?? 0),
            (int) ($summary['transactions'] ?? 0),
            (int) ($summary['orders_touched'] ?? 0),
            (int) ($summary['orders_updated'] ?? 0)
        ));

        return self::SUCCESS;
    }
}

