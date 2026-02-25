<?php

use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductIdentifierCostComponent;
use App\Models\ProductIdentifierCostLayer;
use App\Models\ProductIdentifierSalePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createPricingContext(): array
{
    $user = User::factory()->create();
    $marketplace = Marketplace::query()->create([
        'id' => 'ATVPDKIKX0DER',
        'name' => 'Amazon.com',
        'country_code' => 'US',
        'default_currency' => 'USD',
    ]);

    $product = Product::query()->create([
        'name' => 'Test Product',
        'status' => 'active',
    ]);

    ProductIdentifier::query()->create([
        'product_id' => $product->id,
        'identifier_type' => 'asin',
        'identifier_value' => 'B000TEST01',
        'marketplace_id' => $marketplace->id,
        'region' => 'NA',
        'is_primary' => true,
    ]);

    $sku = ProductIdentifier::query()->create([
        'product_id' => $product->id,
        'identifier_type' => 'seller_sku',
        'identifier_value' => 'SKU-1',
        'marketplace_id' => $marketplace->id,
        'region' => 'NA',
        'is_primary' => false,
    ]);

    return [$user, $product, $sku];
}

test('cost layer and component CRUD works and recalculates unit landed cost', function () {
    [$user, $product, $identifier] = createPricingContext();

    $this->actingAs($user)
        ->post(route('products.identifiers.cost_layers.store', [$product, $identifier]), [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-01-31',
            'currency' => 'USD',
            'allocation_basis' => 'per_shipment',
            'shipment_reference' => 'SHIP-1',
        ])
        ->assertSessionHasNoErrors();

    $layer = ProductIdentifierCostLayer::query()->firstOrFail();

    $this->actingAs($user)
        ->post(route('products.identifiers.cost_components.store', [$product, $identifier, $layer]), [
            'component_type' => 'shipping',
            'amount' => 100,
            'amount_basis' => 'per_shipment',
            'allocation_quantity' => 20,
            'allocation_unit' => 'unit',
        ])
        ->assertSessionHasNoErrors();

    $layer->refresh();
    expect((float) $layer->unit_landed_cost)->toBe(5.0);

    $component = ProductIdentifierCostComponent::query()->firstOrFail();

    $this->actingAs($user)
        ->delete(route('products.identifiers.cost_components.destroy', [$product, $identifier, $layer, $component]))
        ->assertSessionHasNoErrors();

    $layer->refresh();
    expect((float) $layer->unit_landed_cost)->toBe(0.0);

    $this->actingAs($user)
        ->delete(route('products.identifiers.cost_layers.destroy', [$product, $identifier, $layer]))
        ->assertSessionHasNoErrors();

    expect(ProductIdentifierCostLayer::query()->count())->toBe(0);
});

test('cost layer rejects overlapping windows and missing currency', function () {
    [$user, $product, $identifier] = createPricingContext();

    ProductIdentifierCostLayer::query()->create([
        'product_identifier_id' => $identifier->id,
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-01-31',
        'currency' => 'USD',
        'allocation_basis' => 'per_unit',
        'unit_landed_cost' => 2,
    ]);

    $this->actingAs($user)
        ->post(route('products.identifiers.cost_layers.store', [$product, $identifier]), [
            'effective_from' => '2026-01-15',
            'effective_to' => '2026-02-15',
            'currency' => 'USD',
            'allocation_basis' => 'per_unit',
        ])
        ->assertSessionHasErrors(['effective_from']);

    $this->actingAs($user)
        ->post(route('products.identifiers.cost_layers.store', [$product, $identifier]), [
            'effective_from' => '2026-02-01',
            'effective_to' => '2026-02-15',
            'allocation_basis' => 'per_unit',
        ])
        ->assertSessionHasErrors(['currency']);
});

test('sale price CRUD and duplicate active windows validation', function () {
    [$user, $product, $identifier] = createPricingContext();

    $this->actingAs($user)
        ->post(route('products.identifiers.sale_prices.store', [$product, $identifier]), [
            'effective_from' => '2026-03-01',
            'currency' => 'USD',
            'sale_price' => 19.99,
            'source' => 'manual',
        ])
        ->assertSessionHasNoErrors();

    $salePrice = ProductIdentifierSalePrice::query()->firstOrFail();

    $this->actingAs($user)
        ->post(route('products.identifiers.sale_prices.store', [$product, $identifier]), [
            'effective_from' => '2026-03-15',
            'effective_to' => '2026-03-30',
            'currency' => 'USD',
            'sale_price' => 20.99,
        ])
        ->assertSessionHasErrors(['effective_from']);

    $this->actingAs($user)
        ->delete(route('products.identifiers.sale_prices.destroy', [$product, $identifier, $salePrice]))
        ->assertSessionHasNoErrors();

    expect(ProductIdentifierSalePrice::query()->count())->toBe(0);
});

test('identifier selection requires asin and sku in same marketplace', function () {
    $user = User::factory()->create();
    $marketplace = Marketplace::query()->create([
        'id' => 'A1PA6795UKMFR9',
        'name' => 'Amazon.de',
        'country_code' => 'DE',
        'default_currency' => 'EUR',
    ]);

    $product = Product::query()->create(['name' => 'Only Asin Product', 'status' => 'active']);
    $identifier = ProductIdentifier::query()->create([
        'product_id' => $product->id,
        'identifier_type' => 'asin',
        'identifier_value' => 'B000NO-SKU',
        'marketplace_id' => $marketplace->id,
    ]);

    $this->actingAs($user)
        ->post(route('products.identifiers.sale_prices.store', [$product, $identifier]), [
            'effective_from' => '2026-03-01',
            'currency' => 'EUR',
            'sale_price' => 11.99,
        ])
        ->assertSessionHasErrors(['identifier']);
});
