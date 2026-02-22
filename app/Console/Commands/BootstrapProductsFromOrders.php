<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductIdentifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BootstrapProductsFromOrders extends Command
{
    protected $signature = 'products:bootstrap-from-orders {--limit=1000}';

    protected $description = 'Create products + identifiers from existing order items and link order_items.product_id.';

    public function handle(): int
    {
        $limit = max(1, min((int) $this->option('limit'), 50000));

        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.amazon_order_id', '=', 'oi.amazon_order_id')
            ->select([
                'oi.id as id',
                'oi.seller_sku as seller_sku',
                'oi.asin as asin',
                'oi.title as title',
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

        $createdProducts = 0;
        $linkedRows = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $marketplaceId = trim((string) ($row->marketplace_id ?? ''));
            $sellerSku = trim((string) ($row->seller_sku ?? ''));
            $asin = strtoupper(trim((string) ($row->asin ?? '')));
            $title = trim((string) ($row->title ?? ''));

            if ($sellerSku === '' && $asin === '') {
                $skipped++;
                continue;
            }

            $productId = null;
            if ($sellerSku !== '') {
                $productId = ProductIdentifier::query()
                    ->where('identifier_type', 'seller_sku')
                    ->where('identifier_value', $sellerSku)
                    ->where('marketplace_id', $marketplaceId !== '' ? $marketplaceId : null)
                    ->value('product_id');
            }

            if ($productId === null && $asin !== '') {
                $productId = ProductIdentifier::query()
                    ->where('identifier_type', 'asin')
                    ->where('identifier_value', $asin)
                    ->where('marketplace_id', $marketplaceId !== '' ? $marketplaceId : null)
                    ->value('product_id');
            }

            if ($productId === null) {
                $product = Product::query()->create([
                    'name' => $title !== '' ? $title : ($sellerSku !== '' ? $sellerSku : $asin),
                    'status' => 'active',
                ]);
                $productId = (int) $product->id;
                $createdProducts++;
            }

            if ($sellerSku !== '') {
                ProductIdentifier::query()->updateOrCreate(
                    [
                        'identifier_type' => 'seller_sku',
                        'identifier_value' => $sellerSku,
                        'marketplace_id' => $marketplaceId !== '' ? $marketplaceId : null,
                    ],
                    [
                        'product_id' => $productId,
                        'is_primary' => true,
                    ]
                );
            }

            if ($asin !== '') {
                ProductIdentifier::query()->updateOrCreate(
                    [
                        'identifier_type' => 'asin',
                        'identifier_value' => $asin,
                        'marketplace_id' => $marketplaceId !== '' ? $marketplaceId : null,
                    ],
                    [
                        'product_id' => $productId,
                        'is_primary' => $sellerSku === '',
                    ]
                );
            }

            DB::table('order_items')
                ->where('id', (int) $row->id)
                ->update([
                    'product_id' => $productId,
                    'updated_at' => now(),
                ]);

            $linkedRows++;
        }

        $this->info("Processed {$rows->count()} rows. Created products: {$createdProducts}. Linked rows: {$linkedRows}. Skipped: {$skipped}.");

        return Command::SUCCESS;
    }
}
