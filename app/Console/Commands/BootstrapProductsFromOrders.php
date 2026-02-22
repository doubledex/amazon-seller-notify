<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductIdentifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BootstrapProductsFromOrders extends Command
{
    protected $signature = 'products:bootstrap-from-orders {--limit=1000} {--reset=0}';

    protected $description = 'Create products from unique ASINs, attach identifiers, and link order_items.product_id.';

    public function handle(): int
    {
        $limit = max(1, min((int) $this->option('limit'), 50000));
        $reset = (string) $this->option('reset') !== '0';

        if ($reset) {
            DB::table('order_items')->update([
                'product_id' => null,
                'updated_at' => now(),
            ]);
            ProductIdentifier::query()->delete();
            Product::query()->delete();
            $this->info('Reset existing product links and product tables.');
        }

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
            ->whereRaw("TRIM(COALESCE(oi.asin, '')) <> ''")
            ->orderBy('oi.id')
            ->limit($limit)
            ->get();

        $createdProducts = 0;
        $linkedRows = 0;
        $skipped = 0;
        $asinMap = [];

        foreach ($rows as $row) {
            $marketplaceId = trim((string) ($row->marketplace_id ?? ''));
            $sellerSku = trim((string) ($row->seller_sku ?? ''));
            $asin = strtoupper(trim((string) ($row->asin ?? '')));
            $title = trim((string) ($row->title ?? ''));

            if ($asin === '') {
                $skipped++;
                continue;
            }

            $productId = $asinMap[$asin] ?? null;
            if ($productId === null) {
                $productId = ProductIdentifier::query()
                    ->where('identifier_type', 'asin')
                    ->where('identifier_value', $asin)
                    ->whereNull('marketplace_id')
                    ->value('product_id');
                if ($productId !== null) {
                    $asinMap[$asin] = (int) $productId;
                }
            }

            if ($productId === null) {
                $product = Product::query()->create([
                    'name' => $title !== '' ? $title : $asin,
                    'status' => 'active',
                ]);
                $productId = (int) $product->id;
                $createdProducts++;
                $asinMap[$asin] = $productId;
            }

            ProductIdentifier::query()->updateOrCreate(
                [
                    'identifier_type' => 'asin',
                    'identifier_value' => $asin,
                    'marketplace_id' => null,
                ],
                [
                    'product_id' => $productId,
                    'is_primary' => true,
                ]
            );

            if ($sellerSku !== '') {
                ProductIdentifier::query()->updateOrCreate(
                    [
                        'identifier_type' => 'seller_sku',
                        'identifier_value' => $sellerSku,
                        'marketplace_id' => $marketplaceId !== '' ? $marketplaceId : null,
                    ],
                    [
                        'product_id' => $productId,
                        'is_primary' => false,
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
