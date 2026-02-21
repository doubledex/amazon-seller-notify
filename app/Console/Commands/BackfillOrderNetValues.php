<?php

namespace App\Console\Commands;

use App\Models\OrderItem;
use App\Services\OrderNetValueService;
use Illuminate\Console\Command;

class BackfillOrderNetValues extends Command
{
    protected $signature = 'orders:backfill-net-values {--limit=0}';
    protected $description = 'Recompute per-line and per-order net sales ex tax from order item raw payloads.';

    public function handle(OrderNetValueService $netService): int
    {
        $limit = (int) $this->option('limit');
        $updatedItems = 0;
        $affectedOrders = [];

        OrderItem::query()
            ->orderBy('id')
            ->chunkById(300, function ($items) use (&$updatedItems, $limit, &$affectedOrders, $netService) {
                foreach ($items as $item) {
                    if ($limit > 0 && $updatedItems >= $limit) {
                        return false;
                    }

                    $raw = $item->raw_item;
                    if (is_string($raw)) {
                        $decoded = json_decode($raw, true);
                        $raw = is_array($decoded) ? $decoded : [];
                    }
                    if (!is_array($raw)) {
                        $raw = [];
                    }

                    $values = $netService->valuesFromApiItem($raw);
                    $item->line_net_ex_tax = $values['line_net_ex_tax'];
                    $item->line_net_currency = $values['line_net_currency'];
                    $item->save();

                    if (!empty($item->amazon_order_id)) {
                        $affectedOrders[(string) $item->amazon_order_id] = true;
                    }
                    $updatedItems++;
                }

                return null;
            });

        foreach (array_keys($affectedOrders) as $orderId) {
            $netService->refreshOrderNet($orderId);
        }

        $this->info("Updated {$updatedItems} order items and recalculated " . count($affectedOrders) . " orders.");

        return Command::SUCCESS;
    }
}
