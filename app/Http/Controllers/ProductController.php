<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Marketplace;
use App\Models\ProductIdentifier;
use App\Models\Family;
use App\Models\Mcu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $marketplace = trim((string) $request->query('marketplace', ''));
        $mcuSort = strtolower(trim((string) $request->query('mcu_sort', 'name')));
        $mcuDir = strtolower(trim((string) $request->query('mcu_dir', 'asc')));
        $allowedSorts = ['name', 'identifier', 'asin', 'seller_sku', 'fnsku', 'barcode'];
        if (!in_array($mcuSort, $allowedSorts, true)) {
            $mcuSort = 'name';
        }
        if (!in_array($mcuDir, ['asc', 'desc'], true)) {
            $mcuDir = 'asc';
        }
        $perPage = 15;

        $families = Family::query()
            ->withCount('mcus')
            ->with([
                'mcus' => function ($query) {
                    $query->with([
                        'identifiers' => fn ($identifierQuery) => $identifierQuery->orderBy('identifier_type')->orderBy('identifier_value'),
                        'sellableUnits' => fn ($sellableUnitQuery) => $sellableUnitQuery->orderBy('id'),
                        'marketplaceProjections' => fn ($projectionQuery) => $projectionQuery->orderBy('marketplace')->orderBy('seller_sku'),
                    ])->orderBy('id');
                },
            ])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', '%' . $q . '%')
                        ->orWhere('parent_asin', 'like', '%' . strtoupper($q) . '%')
                        ->orWhereHas('mcus', function ($mcuQuery) use ($q) {
                            $mcuQuery->where('name', 'like', '%' . $q . '%')
                                ->orWhereHas('identifiers', function ($identifierQuery) use ($q) {
                                    $identifierQuery->where('identifier_value', 'like', '%' . strtoupper($q) . '%');
                                })
                                ->orWhereHas('marketplaceProjections', function ($projectionQuery) use ($q) {
                                    $projectionQuery->where('child_asin', 'like', '%' . strtoupper($q) . '%')
                                        ->orWhere('seller_sku', 'like', '%' . $q . '%')
                                        ->orWhere('parent_asin', 'like', '%' . strtoupper($q) . '%')
                                        ->orWhere('fnsku', 'like', '%' . strtoupper($q) . '%');
                                });
                        });
                });
            })
            ->when($marketplace !== '', function ($query) use ($marketplace) {
                $query->where(function ($familyQuery) use ($marketplace) {
                    $familyQuery->where('marketplace', $marketplace)
                        ->orWhereHas('mcus.marketplaceProjections', function ($projectionQuery) use ($marketplace) {
                            $projectionQuery->where('marketplace', $marketplace);
                        });
                });
            })
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
        $families->setCollection(
            $families->getCollection()->map(function (Family $family) use ($mcuSort, $mcuDir) {
                $family->setRelation('mcus', $this->sortMcuCollection($family->mcus, $mcuSort, $mcuDir));
                return $family;
            })
        );

        $unassignedMcus = Mcu::query()
            ->whereNull('family_id')
            ->with([
                'identifiers' => fn ($query) => $query->orderBy('identifier_type')->orderBy('identifier_value'),
                'sellableUnits' => fn ($query) => $query->orderBy('id'),
                'marketplaceProjections' => fn ($query) => $query->orderBy('marketplace')->orderBy('seller_sku'),
            ])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', '%' . $q . '%')
                        ->orWhereHas('identifiers', function ($identifierQuery) use ($q) {
                            $identifierQuery->where('identifier_value', 'like', '%' . strtoupper($q) . '%');
                        })
                        ->orWhereHas('marketplaceProjections', function ($projectionQuery) use ($q) {
                            $projectionQuery->where('child_asin', 'like', '%' . strtoupper($q) . '%')
                                ->orWhere('seller_sku', 'like', '%' . $q . '%')
                                ->orWhere('parent_asin', 'like', '%' . strtoupper($q) . '%')
                                ->orWhere('fnsku', 'like', '%' . strtoupper($q) . '%');
                        });
                });
            })
            ->when($marketplace !== '', function ($query) use ($marketplace) {
                $query->whereHas('marketplaceProjections', function ($projectionQuery) use ($marketplace) {
                    $projectionQuery->where('marketplace', $marketplace);
                });
            })
            ->orderBy('id')
            ->simplePaginate(10, ['*'], 'unassigned_page')
            ->withQueryString();
        $unassignedMcus->setCollection($this->sortMcuCollection($unassignedMcus->getCollection(), $mcuSort, $mcuDir));

        $marketplaceOptions = Marketplace::query()
            ->orderBy('name')
            ->get(['id', 'name', 'country_code']);
        $familyOptions = Family::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);

        return view('products.index', [
            'families' => $families,
            'unassignedMcus' => $unassignedMcus,
            'q' => $q,
            'marketplace' => $marketplace,
            'mcuSort' => $mcuSort,
            'mcuDir' => $mcuDir,
            'marketplaceOptions' => $marketplaceOptions,
            'familyOptions' => $familyOptions,
        ]);
    }

    private function sortMcuCollection(Collection $mcus, string $sort, string $dir): Collection
    {
        $sorted = $mcus->sortBy(function (Mcu $mcu) use ($sort) {
            return strtolower($this->mcuSortValue($mcu, $sort));
        });

        return $dir === 'desc' ? $sorted->reverse()->values() : $sorted->values();
    }

    private function mcuSortValue(Mcu $mcu, string $sort): string
    {
        $identifier = $mcu->identifiers->first();
        $projection = $mcu->marketplaceProjections->first();
        $sellableUnit = $mcu->sellableUnits->first();

        return match ($sort) {
            'asin' => (string) ($mcu->identifiers->firstWhere('identifier_type', 'asin')?->identifier_value ?? $projection->child_asin ?? ''),
            'seller_sku' => (string) ($mcu->identifiers->firstWhere('identifier_type', 'seller_sku')?->identifier_value ?? $projection->seller_sku ?? ''),
            'fnsku' => (string) ($mcu->identifiers->firstWhere('identifier_type', 'fnsku')?->identifier_value ?? $projection->fnsku ?? ''),
            'barcode' => (string) ($sellableUnit->barcode ?? ''),
            'identifier' => (string) ($identifier->identifier_value ?? $projection->child_asin ?? $projection->seller_sku ?? $projection->fnsku ?? $sellableUnit->barcode ?? ''),
            default => (string) ($mcu->name ?? ''),
        };
    }

    public function updateFamily(Request $request, Family $family): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $family->update([
            'name' => trim((string) $validated['name']),
        ]);

        return back()->with('status', 'Family name updated.');
    }

    public function storeFamily(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'marketplace' => ['required', 'string', 'exists:marketplaces,id'],
            'parent_asin' => ['nullable', 'string', 'max:32'],
        ]);

        $parentAsin = strtoupper(trim((string) ($validated['parent_asin'] ?? '')));
        if ($parentAsin === '') {
            $parentAsin = null;
        }

        Family::query()->create([
            'name' => trim((string) $validated['name']),
            'marketplace' => trim((string) $validated['marketplace']),
            'parent_asin' => $parentAsin,
        ]);

        return redirect()->route('products.index')->with('status', 'Family created.');
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
        $product->load([
            'identifiers' => function ($q) {
                $q->with([
                    'marketplace',
                    'costLayers' => function ($layerQuery) {
                        $layerQuery->with('components')->orderByDesc('effective_from');
                    },
                    'salePrices' => function ($salePriceQuery) {
                        $salePriceQuery->orderByDesc('effective_from');
                    },
                ])->orderByDesc('is_primary')->orderBy('identifier_type')->orderBy('identifier_value');
            },
        ]);

        $marketplaces = Marketplace::query()->orderBy('name')->get(['id', 'name', 'country_code']);

        return view('products.show', [
            'product' => $product,
            'marketplaces' => $marketplaces,
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
