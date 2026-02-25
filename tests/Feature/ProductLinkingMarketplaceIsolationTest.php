<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('bootstrap command keeps same asin isolated by marketplace', function () {
    Order::query()->create([
        'amazon_order_id' => 'ORDER-US-1',
        'marketplace_id' => 'ATVPDKIKX0DER',
    ]);

    Order::query()->create([
        'amazon_order_id' => 'ORDER-DE-1',
        'marketplace_id' => 'A1PA6795UKMFR9',
    ]);

    $usItem = OrderItem::query()->create([
        'amazon_order_id' => 'ORDER-US-1',
        'order_item_id' => 'US-ITEM-1',
        'asin' => 'B00MARKET01',
        'seller_sku' => 'US-SKU-1',
        'title' => 'US Product',
    ]);

    $deItem = OrderItem::query()->create([
        'amazon_order_id' => 'ORDER-DE-1',
        'order_item_id' => 'DE-ITEM-1',
        'asin' => 'B00MARKET01',
        'seller_sku' => 'DE-SKU-1',
        'title' => 'DE Product',
    ]);

    $this->artisan('products:bootstrap-from-orders', ['--limit' => 100])
        ->assertSuccessful();

    $usAsin = ProductIdentifier::query()
        ->where('identifier_type', 'asin')
        ->where('identifier_value', 'B00MARKET01')
        ->where('marketplace_id', 'ATVPDKIKX0DER')
        ->firstOrFail();

    $deAsin = ProductIdentifier::query()
        ->where('identifier_type', 'asin')
        ->where('identifier_value', 'B00MARKET01')
        ->where('marketplace_id', 'A1PA6795UKMFR9')
        ->firstOrFail();

    expect($usAsin->product_id)->not->toBe($deAsin->product_id);
    expect(Product::query()->count())->toBe(2);

    expect($usItem->fresh()->product_id)->toBe($usAsin->product_id);
    expect($deItem->fresh()->product_id)->toBe($deAsin->product_id);
});

test('bootstrap command uses asin precedence when asin and sku resolve to different products', function () {
    $asinProduct = Product::query()->create([
        'name' => 'ASIN Product',
        'status' => 'active',
    ]);

    $skuProduct = Product::query()->create([
        'name' => 'SKU Product',
        'status' => 'active',
    ]);

    ProductIdentifier::query()->create([
        'product_id' => $asinProduct->id,
        'identifier_type' => 'asin',
        'identifier_value' => 'B00CONFLICT1',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'is_primary' => true,
    ]);

    ProductIdentifier::query()->create([
        'product_id' => $skuProduct->id,
        'identifier_type' => 'seller_sku',
        'identifier_value' => 'SKU-CONFLICT',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'is_primary' => false,
    ]);

    Order::query()->create([
        'amazon_order_id' => 'ORDER-US-CONFLICT',
        'marketplace_id' => 'ATVPDKIKX0DER',
    ]);

    $item = OrderItem::query()->create([
        'amazon_order_id' => 'ORDER-US-CONFLICT',
        'order_item_id' => 'US-CONFLICT-ITEM',
        'asin' => 'B00CONFLICT1',
        'seller_sku' => 'SKU-CONFLICT',
    ]);

    $this->artisan('products:bootstrap-from-orders', ['--limit' => 100])
        ->assertSuccessful();

    expect($item->fresh()->product_id)->toBe($asinProduct->id);
});

test('orders link-products prioritizes marketplace specific identifiers before global fallback', function () {
    $globalProduct = Product::query()->create(['name' => 'Global Product', 'status' => 'active']);
    $usProduct = Product::query()->create(['name' => 'US Product', 'status' => 'active']);
    $deProduct = Product::query()->create(['name' => 'DE Product', 'status' => 'active']);

    ProductIdentifier::query()->create([
        'product_id' => $globalProduct->id,
        'identifier_type' => 'asin',
        'identifier_value' => 'B00LINK001',
        'marketplace_id' => null,
        'is_primary' => true,
    ]);

    ProductIdentifier::query()->create([
        'product_id' => $usProduct->id,
        'identifier_type' => 'asin',
        'identifier_value' => 'B00LINK001',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'is_primary' => true,
    ]);

    ProductIdentifier::query()->create([
        'product_id' => $deProduct->id,
        'identifier_type' => 'asin',
        'identifier_value' => 'B00LINK001',
        'marketplace_id' => 'A1PA6795UKMFR9',
        'is_primary' => true,
    ]);

    Order::query()->insert([
        ['amazon_order_id' => 'ORDER-US-LINK', 'marketplace_id' => 'ATVPDKIKX0DER', 'created_at' => now(), 'updated_at' => now()],
        ['amazon_order_id' => 'ORDER-DE-LINK', 'marketplace_id' => 'A1PA6795UKMFR9', 'created_at' => now(), 'updated_at' => now()],
        ['amazon_order_id' => 'ORDER-FR-LINK', 'marketplace_id' => 'A13V1IB3VIYZZH', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $usItem = OrderItem::query()->create([
        'amazon_order_id' => 'ORDER-US-LINK',
        'order_item_id' => 'US-LINK-ITEM',
        'asin' => 'B00LINK001',
    ]);

    $deItem = OrderItem::query()->create([
        'amazon_order_id' => 'ORDER-DE-LINK',
        'order_item_id' => 'DE-LINK-ITEM',
        'asin' => 'B00LINK001',
    ]);

    $frItem = OrderItem::query()->create([
        'amazon_order_id' => 'ORDER-FR-LINK',
        'order_item_id' => 'FR-LINK-ITEM',
        'asin' => 'B00LINK001',
    ]);

    $this->artisan('orders:link-products', ['--limit' => 100])
        ->assertSuccessful();

    expect($usItem->fresh()->product_id)->toBe($usProduct->id);
    expect($deItem->fresh()->product_id)->toBe($deProduct->id);
    expect($frItem->fresh()->product_id)->toBe($globalProduct->id);
});
