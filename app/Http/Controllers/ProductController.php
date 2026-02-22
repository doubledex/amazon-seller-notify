<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductIdentifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->withCount(['identifiers', 'costLayers'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', '%' . $q . '%')
                        ->orWhere('id', $q)
                        ->orWhereHas('identifiers', function ($idq) use ($q) {
                            $idq->where('identifier_value', 'like', '%' . $q . '%');
                        });
                });
            })
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'q' => $q,
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
        } else {
            ProductIdentifier::query()->create([
                'product_id' => $product->id,
                'identifier_type' => $type,
                'identifier_value' => $value,
                'marketplace_id' => $marketplaceId,
                'region' => $region,
                'is_primary' => !empty($validated['is_primary']),
            ]);
        }

        return back()->with('status', 'Identifier saved.');
    }

    public function destroyIdentifier(ProductIdentifier $identifier): RedirectResponse
    {
        $productId = (int) $identifier->product_id;
        $identifier->delete();

        return redirect()->route('products.show', ['product' => $productId])->with('status', 'Identifier removed.');
    }
}
