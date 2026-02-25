<?php

namespace App\Console\Commands;

use App\Models\ProductIdentifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkOrderItemsToProducts extends Command
{
    protected $signature = 'orders:link-products {--limit=1000}';

    protected $description = 'Link order_items to products using product_identifiers (ASIN first, then SKU).';

    public function handle(): int
    {
        $limit = max(1, min((int) $this->option('limit'), 20000));

        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.amazon_order_id', '=', 'oi.amazon_order_id')
            ->select([
                'oi.id as id',
                'oi.seller_sku as seller_sku',
                'oi.asin as asin',
                'o.marketplace_id as marketplace_id',
            ])
            ->whereNull('oi.product_id')
            ->where(function ($q) {
                $q->whereRaw("TRIM(COALESCE(oi.seller_sku, '')) <> ''")
                    ->orWhereRaw("TRIM(COALESCE(oi.asin, '')) <> ''");
            })
            ->orderBy('oi.id')
            ->limit($limit)
            ->get();

        $linked = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $marketplaceId = trim((string) ($row->marketplace_id ?? ''));
            $sellerSku = trim((string) ($row->seller_sku ?? ''));
            $asin = strtoupper(trim((string) ($row->asin ?? '')));

            $productId = null;

            if ($asin !== '') {
                $productId = $this->findProductId('asin', $asin, $marketplaceId);
                if ($productId === null) {
                    $productId = $this->findProductId('asin', $asin, null);
                }
            }

            if ($productId === null && $sellerSku !== '') {
                $productId = $this->findProductId('seller_sku', $sellerSku, $marketplaceId);
                if ($productId === null) {
                    $productId = $this->findProductId('seller_sku', $sellerSku, null);
                }
            }

            if ($productId === null) {
                $skipped++;
                continue;
            }

            DB::table('order_items')
                ->where('id', (int) $row->id)
                ->update([
                    'product_id' => $productId,
                    'updated_at' => now(),
                ]);

            $linked++;
        }

        $this->info("Processed {$rows->count()} rows. Linked {$linked}, skipped {$skipped}.");

        return Command::SUCCESS;
    }

    private function findProductId(string $type, string $value, ?string $marketplaceId): ?int
    {
        $query = ProductIdentifier::query()
            ->where('identifier_type', $type)
            ->where('identifier_value', $value);

        if ($marketplaceId === null || $marketplaceId === '') {
            $query->whereNull('marketplace_id');
        } else {
            $query->where('marketplace_id', $marketplaceId);
        }

        return $query->value('product_id');
    }
}
