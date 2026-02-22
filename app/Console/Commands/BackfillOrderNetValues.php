<?php

namespace App\Console\Commands;

use App\Models\OrderItem;
use App\Services\OrderNetValueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
                $orderIds = $items->pluck('amazon_order_id')->filter()->unique()->values()->all();
                $orderCountryMap = [];
                if (!empty($orderIds)) {
                    $rows = DB::table('orders')
                        ->leftJoin('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
                        ->whereIn('orders.amazon_order_id', $orderIds)
                        ->select([
                            'orders.amazon_order_id as amazon_order_id',
                            'marketplaces.country_code as country_code',
                        ])
                        ->get();
                    foreach ($rows as $row) {
                        $orderId = (string) ($row->amazon_order_id ?? '');
                        if ($orderId === '') {
                            continue;
                        }
                        $orderCountryMap[$orderId] = strtoupper(trim((string) ($row->country_code ?? '')));
                    }
                }

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

                    $countryCode = $orderCountryMap[(string) $item->amazon_order_id] ?? null;
                    $values = $netService->valuesFromApiItem($raw, $countryCode);
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
