<?php

namespace App\Console\Commands;

use App\Services\AmazonOrderFeeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncAmazonOrderFees extends Command
{
    protected $signature = 'fees:sync {--from=} {--to=} {--days=7} {--region= : Optional SP-API region (EU|NA|FE)}';
    protected $description = 'Sync Amazon order fees from SP-API Finances and store on orders.';

    public function handle(AmazonOrderFeeSyncService $service): int
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
            'Fee sync complete. Regions: %d, Events scanned: %d, Orders updated: %d.',
            (int) ($summary['regions'] ?? 0),
            (int) ($summary['events'] ?? 0),
            (int) ($summary['orders_updated'] ?? 0)
        ));

        return self::SUCCESS;
    }
}

