<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Console\Command;

class BackfillMarketplaceFacilitatorFlag extends Command
{
    protected $signature = 'orders:backfill-mf {--chunk=500 : Chunk size}';
    protected $description = 'Backfill marketplace facilitator flag on orders from stored order_items TaxCollection.';

    public function handle(): int
    {
        $chunk = max(50, (int) $this->option('chunk'));
        $updated = 0;
        $processed = 0;

        Order::query()
            ->select(['id', 'amazon_order_id', 'is_marketplace_facilitator'])
            ->whereNull('is_marketplace_facilitator')
            ->chunkById($chunk, function ($orders) use (&$updated, &$processed) {
                $orderIds = $orders->pluck('amazon_order_id')->filter()->values()->all();
                $itemRows = OrderItem::query()
                    ->whereIn('amazon_order_id', $orderIds)
                    ->get(['amazon_order_id', 'raw_item']);

                $mfMap = [];
                foreach ($itemRows as $item) {
                    $orderId = (string) $item->amazon_order_id;
                    if ($orderId === '' || !empty($mfMap[$orderId])) {
                        continue;
                    }

                    $raw = $item->raw_item;
                    if (is_string($raw)) {
                        $decoded = json_decode($raw, true);
                        $raw = is_array($decoded) ? $decoded : [];
                    }

                    if (is_array($raw) && $this->isMarketplaceFacilitatorItem($raw)) {
                        $mfMap[$orderId] = true;
                    }
                }

                foreach ($orders as $order) {
                    $processed++;
                    $value = $mfMap[$order->amazon_order_id] ?? false;
                    $count = Order::query()
                        ->where('id', $order->id)
                        ->whereNull('is_marketplace_facilitator')
                        ->update(['is_marketplace_facilitator' => $value]);
                    $updated += $count;
                }
            });

        $this->info("Processed {$processed} orders, updated {$updated}.");

        return self::SUCCESS;
    }

    private function isMarketplaceFacilitatorItem(array $item): bool
    {
        $taxCollection = $item['TaxCollection'] ?? null;
        if (!is_array($taxCollection)) {
            return false;
        }

        $model = strtolower(trim((string) ($taxCollection['Model'] ?? '')));
        $responsibleParty = strtolower(trim((string) ($taxCollection['ResponsibleParty'] ?? '')));

        if ($model === '' && $responsibleParty === '') {
            return false;
        }

        return str_contains($model, 'marketplace')
            || str_contains($responsibleParty, 'marketplace')
            || str_contains($responsibleParty, 'amazon');
    }
}

