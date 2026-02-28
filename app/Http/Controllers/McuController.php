<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\MarketplaceProjection;
use App\Models\Mcu;
use App\Models\SellableUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class McuController extends Controller
{
    public function show(Mcu $mcu): View
    {
        $mcu->load([
            'family',
            'sellableUnits' => fn ($query) => $query->orderBy('id'),
            'marketplaceProjections' => fn ($query) => $query->orderBy('marketplace')->orderBy('seller_sku'),
            'costContexts' => fn ($query) => $query->orderBy('region')->orderByDesc('effective_from'),
            'inventoryStates' => fn ($query) => $query->orderBy('location'),
        ]);

        return view('mcus.show', [
            'mcu' => $mcu,
            'familyOptions' => Family::query()->orderBy('name')->orderBy('id')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Mcu $mcu): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'base_uom' => ['required', 'string', 'max:32'],
            'net_weight' => ['nullable', 'numeric', 'min:0'],
            'net_length' => ['nullable', 'numeric', 'min:0'],
            'net_width' => ['nullable', 'numeric', 'min:0'],
            'net_height' => ['nullable', 'numeric', 'min:0'],
        ]);

        $mcu->update([
            'name' => $this->nullableTrimmed($validated['name'] ?? null),
            'base_uom' => trim((string) $validated['base_uom']),
            'net_weight' => $validated['net_weight'] ?? null,
            'net_length' => $validated['net_length'] ?? null,
            'net_width' => $validated['net_width'] ?? null,
            'net_height' => $validated['net_height'] ?? null,
        ]);

        return back()->with('status', 'MCU updated.');
    }

    public function storeProjection(Request $request, Mcu $mcu): RedirectResponse
    {
        $validated = $this->validateProjection($request);

        $sellableUnit = SellableUnit::query()->firstOrCreate(['mcu_id' => $mcu->id], []);

        MarketplaceProjection::query()->create([
            'sellable_unit_id' => $sellableUnit->id,
            'mcu_id' => $mcu->id,
            'marketplace' => trim((string) $validated['marketplace']),
            'parent_asin' => $this->uppercaseOrNull($validated['parent_asin'] ?? null),
            'child_asin' => strtoupper(trim((string) $validated['child_asin'])),
            'seller_sku' => trim((string) $validated['seller_sku']),
            'fnsku' => $this->uppercaseOrNull($validated['fnsku'] ?? null),
            'fulfilment_type' => strtoupper(trim((string) ($validated['fulfilment_type'] ?? 'MFN'))),
            'fulfilment_region' => strtoupper(trim((string) ($validated['fulfilment_region'] ?? 'EU'))),
            'active' => !empty($validated['active']),
        ]);

        return back()->with('status', 'Identifier row added.');
    }

    public function updateProjection(Request $request, Mcu $mcu, MarketplaceProjection $projection): RedirectResponse
    {
        $this->assertProjectionBelongsToMcu($mcu, $projection);
        $validated = $this->validateProjection($request);

        $projection->update([
            'marketplace' => trim((string) $validated['marketplace']),
            'parent_asin' => $this->uppercaseOrNull($validated['parent_asin'] ?? null),
            'child_asin' => strtoupper(trim((string) $validated['child_asin'])),
            'seller_sku' => trim((string) $validated['seller_sku']),
            'fnsku' => $this->uppercaseOrNull($validated['fnsku'] ?? null),
            'fulfilment_type' => strtoupper(trim((string) ($validated['fulfilment_type'] ?? 'MFN'))),
            'fulfilment_region' => strtoupper(trim((string) ($validated['fulfilment_region'] ?? 'EU'))),
            'active' => !empty($validated['active']),
        ]);

        return back()->with('status', 'Identifier row updated.');
    }

    public function destroyProjection(Mcu $mcu, MarketplaceProjection $projection): RedirectResponse
    {
        $this->assertProjectionBelongsToMcu($mcu, $projection);
        $projection->delete();

        return back()->with('status', 'Identifier row removed.');
    }

    public function upsertSellableUnit(Request $request, Mcu $mcu): RedirectResponse
    {
        $validated = $request->validate([
            'barcode' => ['nullable', 'string', 'max:64'],
            'packaged_weight' => ['nullable', 'numeric', 'min:0'],
            'packaged_length' => ['nullable', 'numeric', 'min:0'],
            'packaged_width' => ['nullable', 'numeric', 'min:0'],
            'packaged_height' => ['nullable', 'numeric', 'min:0'],
        ]);

        $sellableUnit = SellableUnit::query()->firstOrCreate(['mcu_id' => $mcu->id], []);
        $sellableUnit->update([
            'barcode' => $this->nullableTrimmed($validated['barcode'] ?? null),
            'packaged_weight' => $validated['packaged_weight'] ?? null,
            'packaged_length' => $validated['packaged_length'] ?? null,
            'packaged_width' => $validated['packaged_width'] ?? null,
            'packaged_height' => $validated['packaged_height'] ?? null,
        ]);

        return back()->with('status', 'Sellable unit updated.');
    }

    public function updateFamily(Request $request, Mcu $mcu): RedirectResponse
    {
        $validated = $request->validate([
            'family_id' => ['nullable', 'integer', 'exists:families,id'],
        ]);

        $mcu->update([
            'family_id' => $validated['family_id'] ?? null,
        ]);

        return back()->with('status', 'MCU family updated.');
    }

    private function validateProjection(Request $request): array
    {
        return $request->validate([
            'marketplace' => ['required', 'string', 'max:32'],
            'parent_asin' => ['nullable', 'string', 'max:32'],
            'child_asin' => ['required', 'string', 'max:32'],
            'seller_sku' => ['required', 'string', 'max:128'],
            'fnsku' => ['nullable', 'string', 'max:64'],
            'fulfilment_type' => ['nullable', 'in:FBA,MFN'],
            'fulfilment_region' => ['nullable', 'in:EU,NA,FE'],
            'active' => ['nullable', 'boolean'],
        ]);
    }

    private function assertProjectionBelongsToMcu(Mcu $mcu, MarketplaceProjection $projection): void
    {
        if ((int) $projection->mcu_id !== (int) $mcu->id) {
            abort(404);
        }
    }

    private function nullableTrimmed(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function uppercaseOrNull(?string $value): ?string
    {
        $normalized = $this->nullableTrimmed($value);
        return $normalized === null ? null : strtoupper($normalized);
    }
}
