<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductIdentifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $selectedProductId = (int) $request->query('product_id', 0);
        $primaryIdentifierIds = ProductIdentifier::query()
            ->selectRaw('product_id, MIN(id) as primary_identifier_id')
            ->where('is_primary', true)
            ->groupBy('product_id');

        $products = Product::query()
            ->withCount(['identifiers', 'costLayers'])
            ->leftJoinSub($primaryIdentifierIds, 'pi_ids', function ($join) {
                $join->on('pi_ids.product_id', '=', 'products.id');
            })
            ->leftJoin('product_identifiers as pi', 'pi.id', '=', 'pi_ids.primary_identifier_id')
            ->leftJoin('marketplaces as pm', 'pm.id', '=', 'pi.marketplace_id')
            ->select([
                'products.*',
                DB::raw('pi.identifier_type as primary_identifier_type'),
                DB::raw('pi.identifier_value as primary_identifier_value'),
                DB::raw('pi.marketplace_id as primary_marketplace_id'),
                DB::raw('pm.name as primary_marketplace_name'),
                DB::raw('pm.country_code as primary_marketplace_country_code'),
            ])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('products.name', 'like', '%' . $q . '%')
                        ->orWhere('products.id', $q)
                        ->orWhereHas('identifiers', function ($idq) use ($q) {
                            $idq->where('identifier_value', 'like', '%' . $q . '%');
                        });
                });
            })
            ->orderByRaw("CASE WHEN TRIM(COALESCE(pi.identifier_value, '')) = '' THEN 1 ELSE 0 END")
            ->orderBy('pi.identifier_value')
            ->orderBy('products.id')
            ->paginate(50)
            ->withQueryString();

        $productOptions = Product::query()
            ->orderBy('id')
            ->get(['id', 'name']);

        return view('products.index', [
            'products' => $products,
            'q' => $q,
            'productOptions' => $productOptions,
            'selectedProductId' => $selectedProductId,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive,draft'],
            'notes' => ['nullable', 'string'],
        ]);

        $product = Product::query()->create([
            'name' => trim((string) $validated['name']),
            'status' => (string) ($validated['status'] ?? 'active'),
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->route('products.show', $product)->with('status', 'Product created.');
    }

    public function show(Product $product): View
    {
        $product->load(['identifiers' => function ($q) {
            $q->orderByDesc('is_primary')->orderBy('identifier_type')->orderBy('identifier_value');
        }, 'costLayers' => function ($q) {
            $q->orderByDesc('effective_from');
        }]);

        return view('products.show', [
            'product' => $product,
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive,draft'],
            'notes' => ['nullable', 'string'],
        ]);

        $product->update([
            'name' => trim((string) $validated['name']),
            'status' => (string) $validated['status'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('status', 'Product updated.');
    }

    public function storeIdentifier(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'identifier_type' => ['required', 'in:seller_sku,asin,fnsku,upc,ean,other'],
            'identifier_value' => ['required', 'string', 'max:191'],
            'marketplace_id' => ['nullable', 'string', 'exists:marketplaces,id'],
            'region' => ['nullable', 'in:EU,NA,FE'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $type = strtolower(trim((string) $validated['identifier_type']));
        $value = trim((string) $validated['identifier_value']);
        if (in_array($type, ['asin', 'fnsku', 'upc', 'ean'], true)) {
            $value = strtoupper($value);
        }

        $marketplaceId = isset($validated['marketplace_id']) ? trim((string) $validated['marketplace_id']) : null;
        $marketplaceId = $marketplaceId !== '' ? $marketplaceId : null;
        $region = isset($validated['region']) ? strtoupper(trim((string) $validated['region'])) : null;
        $region = $region !== '' ? $region : null;

        $existing = ProductIdentifier::query()
            ->where('identifier_type', $type)
            ->where('identifier_value', $value)
            ->where(function ($q) use ($marketplaceId) {
                if ($marketplaceId === null) {
                    $q->whereNull('marketplace_id');
                } else {
                    $q->where('marketplace_id', $marketplaceId);
                }
            })
            ->first();

        if ($existing && (int) $existing->product_id !== (int) $product->id) {
            return back()->withErrors([
                'identifier_value' => 'This identifier is already assigned to another product.',
            ])->withInput();
        }

        if ($existing) {
            $existing->update([
                'region' => $region,
                'is_primary' => !empty($validated['is_primary']),
            ]);
            if (!empty($validated['is_primary'])) {
                ProductIdentifier::query()
                    ->where('product_id', $product->id)
                    ->where('id', '!=', $existing->id)
                    ->update(['is_primary' => false]);
            }
        } else {
            $created = ProductIdentifier::query()->create([
                'product_id' => $product->id,
                'identifier_type' => $type,
                'identifier_value' => $value,
                'marketplace_id' => $marketplaceId,
                'region' => $region,
                'is_primary' => !empty($validated['is_primary']),
            ]);
            if (!empty($validated['is_primary'])) {
                ProductIdentifier::query()
                    ->where('product_id', $product->id)
                    ->where('id', '!=', $created->id)
                    ->update(['is_primary' => false]);
            }
        }

        return back()->with('status', 'Identifier saved.');
    }

    public function updateIdentifier(Request $request, ProductIdentifier $identifier): RedirectResponse
    {
        $product = $identifier->product;
        if (!$product) {
            return back()->withErrors(['identifier_value' => 'Identifier product not found.']);
        }

        $validated = $request->validate([
            'identifier_type' => ['required', 'in:seller_sku,asin,fnsku,upc,ean,other'],
            'identifier_value' => ['required', 'string', 'max:191'],
            'marketplace_id' => ['nullable', 'string', 'exists:marketplaces,id'],
            'region' => ['nullable', 'in:EU,NA,FE'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $type = strtolower(trim((string) $validated['identifier_type']));
        $value = trim((string) $validated['identifier_value']);
        if (in_array($type, ['asin', 'fnsku', 'upc', 'ean'], true)) {
            $value = strtoupper($value);
        }

        $marketplaceId = isset($validated['marketplace_id']) ? trim((string) $validated['marketplace_id']) : null;
        $marketplaceId = $marketplaceId !== '' ? $marketplaceId : null;
        $region = isset($validated['region']) ? strtoupper(trim((string) $validated['region'])) : null;
        $region = $region !== '' ? $region : null;
        $isPrimary = !empty($validated['is_primary']);

        $conflict = ProductIdentifier::query()
            ->where('id', '!=', $identifier->id)
            ->where('identifier_type', $type)
            ->where('identifier_value', $value)
            ->where(function ($q) use ($marketplaceId) {
                if ($marketplaceId === null) {
                    $q->whereNull('marketplace_id');
                } else {
                    $q->where('marketplace_id', $marketplaceId);
                }
            })
            ->first();

        if ($conflict && (int) $conflict->product_id !== (int) $product->id) {
            return back()->withErrors([
                'identifier_value' => 'This identifier is already assigned to another product.',
            ])->withInput();
        }

        $identifier->update([
            'identifier_type' => $type,
            'identifier_value' => $value,
            'marketplace_id' => $marketplaceId,
            'region' => $region,
            'is_primary' => $isPrimary,
        ]);

        if ($isPrimary) {
            ProductIdentifier::query()
                ->where('product_id', $product->id)
                ->where('id', '!=', $identifier->id)
                ->update(['is_primary' => false]);
        }

        return redirect()->route('products.show', $product)->with('status', 'Identifier updated.');
    }

    public function destroyIdentifier(ProductIdentifier $identifier): RedirectResponse
    {
        $productId = (int) $identifier->product_id;
        $identifier->delete();

        return redirect()->route('products.show', ['product' => $productId])->with('status', 'Identifier removed.');
    }
}
