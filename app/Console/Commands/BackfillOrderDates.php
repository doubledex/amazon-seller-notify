<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;

class BackfillOrderDates extends Command
{
    protected $signature = 'orders:backfill-dates {--limit=0}';
    protected $description = 'Backfill purchase_date from raw_order JSON.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $updated = 0;

        Order::query()
            ->whereNull('purchase_date')
            ->orderBy('id')
            ->chunkById(200, function ($orders) use (&$updated, $limit) {
                foreach ($orders as $order) {
                    if ($limit > 0 && $updated >= $limit) {
                        return false;
                    }

                    $raw = $order->raw_order;
                    if (is_string($raw)) {
                        $decoded = json_decode($raw, true);
                        $raw = is_array($decoded) ? $decoded : [];
                    }

                    $purchase = $raw['PurchaseDate'] ?? null;
                    if (!$purchase) {
                        continue;
                    }

                    try {
                        $order->purchase_date = (new \DateTime($purchase))->format('Y-m-d H:i:s');
                        $order->save();
                        $updated++;
                    } catch (\Exception $e) {
                        // skip invalid date
                    }
                }
            });

        $this->info("Updated purchase_date on {$updated} orders.");
        return Command::SUCCESS;
    }
}
