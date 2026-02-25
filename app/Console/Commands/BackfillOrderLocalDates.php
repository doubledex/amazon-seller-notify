<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\MarketplaceTimezoneService;
use Illuminate\Console\Command;

class BackfillOrderLocalDates extends Command
{
    protected $signature = 'orders:backfill-local-dates {--limit=0}';
    protected $description = 'Backfill purchase_date_local and purchase_date_local_date from UTC purchase_date.';

    public function handle(MarketplaceTimezoneService $timezoneService): int
    {
        $limit = (int) $this->option('limit');
        $updated = 0;

        Order::query()
            ->whereNotNull('purchase_date')
            ->where(function ($query) {
                $query->whereNull('purchase_date_local')
                    ->orWhereNull('purchase_date_local_date')
                    ->orWhereNull('marketplace_timezone');
            })
            ->orderBy('id')
            ->chunkById(200, function ($orders) use (&$updated, $limit, $timezoneService) {
                foreach ($orders as $order) {
                    if ($limit > 0 && $updated >= $limit) {
                        return false;
                    }

                    $localized = $timezoneService->localizeFromUtc(
                        $order->purchase_date?->format('Y-m-d H:i:s'),
                        $order->marketplace_id,
                        null
                    );

                    $order->purchase_date_local = $localized['purchase_date_local'];
                    $order->purchase_date_local_date = $localized['purchase_date_local_date'];
                    $order->marketplace_timezone = $localized['marketplace_timezone'];
                    $order->save();
                    $updated++;
                }

                return null;
            });

        $this->info("Updated local business date fields on {$updated} orders.");

        return Command::SUCCESS;
    }
}
