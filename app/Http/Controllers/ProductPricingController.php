<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductIdentifierCostComponent;
use App\Models\ProductIdentifierCostLayer;
use App\Models\ProductIdentifierSalePrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductPricingController extends Controller
{
    public function storeCostLayer(Request $request, Product $product, ProductIdentifier $identifier): RedirectResponse
    {
        $this->validateIdentifierSelection($product, $identifier);

        $validated = $request->validate([
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'allocation_basis' => ['required', 'in:per_unit,per_shipment'],
            'shipment_reference' => ['nullable', 'string', 'max:191'],
            'source' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->validateDateWindowOverlap(
            ProductIdentifierCostLayer::query(),
            (int) $identifier->id,
            (string) $validated['effective_from'],
            $validated['effective_to'] ?? null
        );

        ProductIdentifierCostLayer::query()->create([
            'product_identifier_id' => $identifier->id,
            'effective_from' => $validated['effective_from'],
            'effective_to' => $validated['effective_to'] ?? null,
            'currency' => strtoupper((string) $validated['currency']),
            'allocation_basis' => $validated['allocation_basis'],
            'shipment_reference' => $validated['shipment_reference'] ?? null,
            'source' => $validated['source'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'unit_landed_cost' => 0,
        ]);

        return back()->with('status', 'Cost layer saved.');
    }

    public function updateCostLayer(Request $request, Product $product, ProductIdentifier $identifier, ProductIdentifierCostLayer $costLayer): RedirectResponse
    {
        $this->validateIdentifierSelection($product, $identifier);
        $this->assertCostLayerBelongsToIdentifier($costLayer, $identifier);

        $validated = $request->validate([
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'allocation_basis' => ['required', 'in:per_unit,per_shipment'],
            'shipment_reference' => ['nullable', 'string', 'max:191'],
            'source' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->validateDateWindowOverlap(
            ProductIdentifierCostLayer::query()->where('id', '!=', $costLayer->id),
            (int) $identifier->id,
            (string) $validated['effective_from'],
            $validated['effective_to'] ?? null
        );

        $costLayer->update([
            'effective_from' => $validated['effective_from'],
            'effective_to' => $validated['effective_to'] ?? null,
            'currency' => strtoupper((string) $validated['currency']),
            'allocation_basis' => $validated['allocation_basis'],
            'shipment_reference' => $validated['shipment_reference'] ?? null,
            'source' => $validated['source'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('status', 'Cost layer updated.');
    }

    public function destroyCostLayer(Product $product, ProductIdentifier $identifier, ProductIdentifierCostLayer $costLayer): RedirectResponse
    {
        $this->validateIdentifierSelection($product, $identifier);
        $this->assertCostLayerBelongsToIdentifier($costLayer, $identifier);

        $costLayer->delete();

        return back()->with('status', 'Cost layer deleted.');
    }

    public function storeCostComponent(Request $request, Product $product, ProductIdentifier $identifier, ProductIdentifierCostLayer $costLayer): RedirectResponse
    {
        $this->validateIdentifierSelection($product, $identifier);
        $this->assertCostLayerBelongsToIdentifier($costLayer, $identifier);

        $validated = $request->validate([
            'component_type' => ['required', 'string', 'max:32'],
            'amount' => ['required', 'numeric', 'min:0'],
            'amount_basis' => ['required', 'in:per_unit,per_shipment'],
            'allocation_quantity' => ['nullable', 'numeric', 'gt:0'],
            'allocation_unit' => ['nullable', 'string', 'max:24'],
        ]);

        $normalized = $this->normalizeUnitAmount(
            (float) $validated['amount'],
            $validated['amount_basis'],
            $validated['allocation_quantity'] ?? null
        );

        ProductIdentifierCostComponent::query()->create([
            'cost_layer_id' => $costLayer->id,
            'component_type' => strtolower(trim((string) $validated['component_type'])),
            'amount' => $validated['amount'],
            'amount_basis' => $validated['amount_basis'],
            'allocation_quantity' => $validated['allocation_quantity'] ?? null,
            'allocation_unit' => $validated['allocation_unit'] ?? null,
            'normalized_unit_amount' => $normalized,
            'allocation_metadata' => null,
        ]);

        $costLayer->recalculateUnitLandedCost();

        return back()->with('status', 'Cost component saved.');
    }

    public function updateCostComponent(Request $request, Product $product, ProductIdentifier $identifier, ProductIdentifierCostLayer $costLayer, ProductIdentifierCostComponent $component): RedirectResponse
    {
        $this->validateIdentifierSelection($product, $identifier);
        $this->assertCostLayerBelongsToIdentifier($costLayer, $identifier);

        if ((int) $component->cost_layer_id !== (int) $costLayer->id) {
            abort(404);
        }

        $validated = $request->validate([
            'component_type' => ['required', 'string', 'max:32'],
            'amount' => ['required', 'numeric', 'min:0'],
            'amount_basis' => ['required', 'in:per_unit,per_shipment'],
            'allocation_quantity' => ['nullable', 'numeric', 'gt:0'],
            'allocation_unit' => ['nullable', 'string', 'max:24'],
        ]);

        $normalized = $this->normalizeUnitAmount(
            (float) $validated['amount'],
            $validated['amount_basis'],
            $validated['allocation_quantity'] ?? null
        );

        $component->update([
            'component_type' => strtolower(trim((string) $validated['component_type'])),
            'amount' => $validated['amount'],
            'amount_basis' => $validated['amount_basis'],
            'allocation_quantity' => $validated['allocation_quantity'] ?? null,
            'allocation_unit' => $validated['allocation_unit'] ?? null,
            'normalized_unit_amount' => $normalized,
        ]);

        $costLayer->recalculateUnitLandedCost();

        return back()->with('status', 'Cost component updated.');
    }

    public function destroyCostComponent(Product $product, ProductIdentifier $identifier, ProductIdentifierCostLayer $costLayer, ProductIdentifierCostComponent $component): RedirectResponse
    {
        $this->validateIdentifierSelection($product, $identifier);
        $this->assertCostLayerBelongsToIdentifier($costLayer, $identifier);

        if ((int) $component->cost_layer_id !== (int) $costLayer->id) {
            abort(404);
        }

        $component->delete();
        $costLayer->recalculateUnitLandedCost();

        return back()->with('status', 'Cost component deleted.');
    }

    public function storeSalePrice(Request $request, Product $product, ProductIdentifier $identifier): RedirectResponse
    {
        $this->validateIdentifierSelection($product, $identifier);

        $validated = $request->validate([
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'source' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string'],
        ]);

        $currency = strtoupper((string) $validated['currency']);

        $this->validateDateWindowOverlap(
            ProductIdentifierSalePrice::query()->where('currency', $currency),
            (int) $identifier->id,
            (string) $validated['effective_from'],
            $validated['effective_to'] ?? null
        );

        ProductIdentifierSalePrice::query()->create([
            'product_identifier_id' => $identifier->id,
            'effective_from' => $validated['effective_from'],
            'effective_to' => $validated['effective_to'] ?? null,
            'currency' => $currency,
            'sale_price' => $validated['sale_price'],
            'source' => $validated['source'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('status', 'Sale price saved.');
    }

    public function updateSalePrice(Request $request, Product $product, ProductIdentifier $identifier, ProductIdentifierSalePrice $salePrice): RedirectResponse
    {
        $this->validateIdentifierSelection($product, $identifier);
        $this->assertSalePriceBelongsToIdentifier($salePrice, $identifier);

        $validated = $request->validate([
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'source' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string'],
        ]);

        $currency = strtoupper((string) $validated['currency']);

        $this->validateDateWindowOverlap(
            ProductIdentifierSalePrice::query()->where('currency', $currency)->where('id', '!=', $salePrice->id),
            (int) $identifier->id,
            (string) $validated['effective_from'],
            $validated['effective_to'] ?? null
        );

        $salePrice->update([
            'effective_from' => $validated['effective_from'],
            'effective_to' => $validated['effective_to'] ?? null,
            'currency' => $currency,
            'sale_price' => $validated['sale_price'],
            'source' => $validated['source'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('status', 'Sale price updated.');
    }

    public function destroySalePrice(Product $product, ProductIdentifier $identifier, ProductIdentifierSalePrice $salePrice): RedirectResponse
    {
        $this->validateIdentifierSelection($product, $identifier);
        $this->assertSalePriceBelongsToIdentifier($salePrice, $identifier);

        $salePrice->delete();

        return back()->with('status', 'Sale price deleted.');
    }

    private function validateIdentifierSelection(Product $product, ProductIdentifier $identifier): void
    {
        if ((int) $identifier->product_id !== (int) $product->id) {
            abort(404);
        }

        $marketplaceId = trim((string) ($identifier->marketplace_id ?? ''));
        if ($marketplaceId === '') {
            throw ValidationException::withMessages([
                'identifier' => 'Select an identifier with a marketplace to manage costs and sale prices.',
            ]);
        }

        $hasAsin = $product->identifiers()
            ->where('marketplace_id', $marketplaceId)
            ->where('identifier_type', 'asin')
            ->exists();

        $hasSellerSku = $product->identifiers()
            ->where('marketplace_id', $marketplaceId)
            ->where('identifier_type', 'seller_sku')
            ->exists();

        if (! $hasAsin || ! $hasSellerSku) {
            throw ValidationException::withMessages([
                'identifier' => 'Identifier selection requires both ASIN and seller SKU in the same marketplace.',
            ]);
        }
    }

    private function validateDateWindowOverlap($query, int $identifierId, string $effectiveFrom, ?string $effectiveTo): void
    {
        $start = $effectiveFrom;
        $end = $effectiveTo;

        $overlap = $query
            ->where('product_identifier_id', $identifierId)
            ->where(function ($q) use ($start, $end) {
                if ($end === null) {
                    $q->where(function ($openEnded) use ($start) {
                        $openEnded->whereNull('effective_to')
                            ->orWhere('effective_to', '>=', $start);
                    });

                    return;
                }

                $q->where('effective_from', '<=', $end)
                    ->where(function ($close) use ($start) {
                        $close->whereNull('effective_to')
                            ->orWhere('effective_to', '>=', $start);
                    });
            })
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'effective_from' => 'Effective date window overlaps an existing active window.',
            ]);
        }
    }

    private function normalizeUnitAmount(float $amount, string $basis, mixed $allocationQuantity): float
    {
        if ($basis === 'per_unit') {
            return round($amount, 4);
        }

        if ($allocationQuantity === null) {
            throw ValidationException::withMessages([
                'allocation_quantity' => 'Allocation quantity is required when basis is per_shipment.',
            ]);
        }

        $quantity = (float) $allocationQuantity;
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'allocation_quantity' => 'Allocation quantity must be greater than zero.',
            ]);
        }

        return round($amount / $quantity, 4);
    }

    private function assertCostLayerBelongsToIdentifier(ProductIdentifierCostLayer $costLayer, ProductIdentifier $identifier): void
    {
        if ((int) $costLayer->product_identifier_id !== (int) $identifier->id) {
            abort(404);
        }
    }

    private function assertSalePriceBelongsToIdentifier(ProductIdentifierSalePrice $salePrice, ProductIdentifier $identifier): void
    {
        if ((int) $salePrice->product_identifier_id !== (int) $identifier->id) {
            abort(404);
        }
    }
}
