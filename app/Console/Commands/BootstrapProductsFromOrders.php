<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductIdentifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BootstrapProductsFromOrders extends Command
{
    protected $signature = 'products:bootstrap-from-orders {--limit=1000} {--reset=0}';

    protected $description = 'Create products from unique ASINs, attach identifiers, and link order_items.product_id.';

    private array $productPrimaryIdentifierCache = [];

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
        $skuMap = [];

        foreach ($rows as $row) {
            $marketplaceId = trim((string) ($row->marketplace_id ?? ''));
            $sellerSku = trim((string) ($row->seller_sku ?? ''));
            $asin = strtoupper(trim((string) ($row->asin ?? '')));
            $title = trim((string) ($row->title ?? ''));

            if ($asin === '') {
                $skipped++;
                continue;
            }

            $asinKey = $this->identifierCacheKey('asin', $asin, $marketplaceId);
            $skuKey = $this->identifierCacheKey('seller_sku', $sellerSku, $marketplaceId);

            $asinProductId = $asin !== '' ? ($asinMap[$asinKey] ?? null) : null;
            if ($asin !== '' && $asinProductId === null) {
                $asinProductId = $this->findProductId('asin', $asin, $marketplaceId);
                if ($asinProductId !== null) {
                    $asinMap[$asinKey] = $asinProductId;
                }
            }

            $skuProductId = $sellerSku !== '' ? ($skuMap[$skuKey] ?? null) : null;
            if ($sellerSku !== '' && $skuProductId === null) {
                $skuProductId = $this->findProductId('seller_sku', $sellerSku, $marketplaceId);
                if ($skuProductId !== null) {
                    $skuMap[$skuKey] = $skuProductId;
                }
            }

            $productId = $asinProductId ?? $skuProductId;

            if ($asinProductId !== null && $skuProductId !== null && $asinProductId !== $skuProductId) {
                Log::warning('Product identifier conflict while bootstrapping order item', [
                    'order_item_id' => (int) $row->id,
                    'asin' => $asin,
                    'seller_sku' => $sellerSku,
                    'marketplace_id' => $marketplaceId !== '' ? $marketplaceId : null,
                    'chosen_product_id' => $asinProductId,
                    'asin_product_id' => $asinProductId,
                    'sku_product_id' => $skuProductId,
                    'resolution' => 'asin_precedence',
                ]);
            }

            if ($productId === null) {
                $product = Product::query()->create([
                    'name' => $title !== '' ? $title : $asin,
                    'status' => 'active',
                ]);
                $productId = (int) $product->id;
                $createdProducts++;
            }

            if ($asin !== '') {
                $asinMap[$asinKey] = $productId;
            }
            if ($sellerSku !== '') {
                $skuMap[$skuKey] = $productId;
            }

            $asinShouldBePrimary = !$this->productHasPrimaryIdentifier($productId);
            $this->upsertIdentifierSafely('asin', $asin, $marketplaceId !== '' ? $marketplaceId : null, $productId, $asinShouldBePrimary, [
                'order_item_id' => (int) $row->id,
            ]);

            if ($sellerSku !== '') {
                $this->upsertIdentifierSafely('seller_sku', $sellerSku, $marketplaceId !== '' ? $marketplaceId : null, $productId, false, [
                    'order_item_id' => (int) $row->id,
                ]);
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

    private function findProductId(string $type, string $value, string $marketplaceId): ?int
    {
        $query = ProductIdentifier::query()
            ->where('identifier_type', $type)
            ->where('identifier_value', $value);

        if ($marketplaceId === '') {
            $query->whereNull('marketplace_id');
        } else {
            $query->where('marketplace_id', $marketplaceId);
        }

        $productId = $query->value('product_id');

        return $productId !== null ? (int) $productId : null;
    }

    private function upsertIdentifierSafely(string $type, string $value, ?string $marketplaceId, int $productId, bool $isPrimary, array $context = []): void
    {
        $normalizedMarketplaceId = $marketplaceId !== null && $marketplaceId !== '' ? $marketplaceId : null;

        $existing = ProductIdentifier::query()
            ->where('identifier_type', $type)
            ->where('identifier_value', $value)
            ->when($normalizedMarketplaceId === null, fn ($query) => $query->whereNull('marketplace_id'))
            ->when($normalizedMarketplaceId !== null, fn ($query) => $query->where('marketplace_id', $normalizedMarketplaceId))
            ->first();

        if ($existing !== null && (int) $existing->product_id !== $productId) {
            Log::warning('Identifier conflict detected while bootstrapping products from orders', array_merge($context, [
                'identifier_type' => $type,
                'identifier_value' => $value,
                'marketplace_id' => $normalizedMarketplaceId,
                'existing_product_id' => (int) $existing->product_id,
                'candidate_product_id' => $productId,
                'resolution' => 'kept_existing_identifier_mapping',
            ]));

            return;
        }

        if ($existing !== null) {
            if ($isPrimary && !$existing->is_primary && !$this->productHasPrimaryIdentifier($productId)) {
                $existing->update(['is_primary' => true]);
                $this->setProductHasPrimaryIdentifier($productId, true);
            }

            return;
        }

        $resolvedPrimary = $isPrimary && !$this->productHasPrimaryIdentifier($productId);

        ProductIdentifier::query()->create([
            'identifier_type' => $type,
            'identifier_value' => $value,
            'marketplace_id' => $normalizedMarketplaceId,
            'product_id' => $productId,
            'is_primary' => $resolvedPrimary,
        ]);

        if ($resolvedPrimary) {
            $this->setProductHasPrimaryIdentifier($productId, true);
        }
    }

    private function identifierCacheKey(string $type, string $value, string $marketplaceId): string
    {
        return implode('|', [$type, $value, $marketplaceId !== '' ? $marketplaceId : '__NULL__']);
    }

    private function productHasPrimaryIdentifier(int $productId): bool
    {
        if (array_key_exists($productId, $this->productPrimaryIdentifierCache)) {
            return $this->productPrimaryIdentifierCache[$productId];
        }

        $hasPrimary = ProductIdentifier::query()
            ->where('product_id', $productId)
            ->where('is_primary', true)
            ->exists();

        $this->productPrimaryIdentifierCache[$productId] = $hasPrimary;

        return $hasPrimary;
    }

    private function setProductHasPrimaryIdentifier(int $productId, bool $hasPrimary): void
    {
        $this->productPrimaryIdentifierCache[$productId] = $hasPrimary;
    }
}
