<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\McuIdentifier;
use App\Models\MarketplaceProjection;
use App\Models\Mcu;
use App\Models\SellableUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class McuController extends Controller
{
    public function show(Mcu $mcu): View
    {
        $mcu->load([
            'family',
            'sellableUnits' => fn ($query) => $query->orderBy('id'),
            'identifiers' => fn ($query) => $query->orderBy('identifier_type')->orderBy('identifier_value'),
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
        $channel = trim((string) ($validated['channel'] ?? 'amazon'));
        $childAsin = strtoupper(trim((string) ($validated['child_asin'] ?? '')));

        if ($channel === 'amazon' && $childAsin === '') {
            return back()->withErrors(['child_asin' => 'ASIN is required for amazon channel.'])->withInput();
        }
        if ($childAsin !== '') {
            $this->assertAsinAvailable($mcu, $childAsin);
        }

        MarketplaceProjection::query()->create([
            'sellable_unit_id' => $sellableUnit->id,
            'mcu_id' => $mcu->id,
            'channel' => $channel,
            'marketplace' => trim((string) $validated['marketplace']),
            'parent_asin' => $this->uppercaseOrNull($validated['parent_asin'] ?? null),
            'child_asin' => $childAsin !== '' ? $childAsin : null,
            'seller_sku' => trim((string) $validated['seller_sku']),
            'external_product_id' => $this->nullableTrimmed($validated['external_product_id'] ?? null),
            'fnsku' => $this->uppercaseOrNull($validated['fnsku'] ?? null),
            'fulfilment_type' => strtoupper(trim((string) ($validated['fulfilment_type'] ?? 'MFN'))),
            'fulfilment_region' => strtoupper(trim((string) ($validated['fulfilment_region'] ?? 'EU'))),
            'active' => !empty($validated['active']),
        ]);
        $this->syncProjectionIdentifiers(
            $mcu,
            $channel,
            trim((string) $validated['marketplace']),
            $childAsin,
            trim((string) $validated['seller_sku']),
            $this->uppercaseOrNull($validated['fnsku'] ?? null) ?? ''
        );

        return back()->with('status', 'Identifier row added.');
    }

    public function updateProjection(Request $request, Mcu $mcu, MarketplaceProjection $projection): RedirectResponse
    {
        $this->assertProjectionBelongsToMcu($mcu, $projection);
        $validated = $this->validateProjection($request);
        $channel = trim((string) ($validated['channel'] ?? 'amazon'));
        $childAsin = strtoupper(trim((string) ($validated['child_asin'] ?? '')));

        if ($channel === 'amazon' && $childAsin === '') {
            return back()->withErrors(['child_asin' => 'ASIN is required for amazon channel.'])->withInput();
        }
        if ($childAsin !== '') {
            $this->assertAsinAvailable($mcu, $childAsin);
        }

        $projection->update([
            'channel' => $channel,
            'marketplace' => trim((string) $validated['marketplace']),
            'parent_asin' => $this->uppercaseOrNull($validated['parent_asin'] ?? null),
            'child_asin' => $childAsin !== '' ? $childAsin : null,
            'seller_sku' => trim((string) $validated['seller_sku']),
            'external_product_id' => $this->nullableTrimmed($validated['external_product_id'] ?? null),
            'fnsku' => $this->uppercaseOrNull($validated['fnsku'] ?? null),
            'fulfilment_type' => strtoupper(trim((string) ($validated['fulfilment_type'] ?? 'MFN'))),
            'fulfilment_region' => strtoupper(trim((string) ($validated['fulfilment_region'] ?? 'EU'))),
            'active' => !empty($validated['active']),
        ]);
        $this->syncProjectionIdentifiers(
            $mcu,
            $channel,
            trim((string) $validated['marketplace']),
            $childAsin,
            trim((string) $validated['seller_sku']),
            $this->uppercaseOrNull($validated['fnsku'] ?? null) ?? ''
        );

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

        $barcode = $this->nullableTrimmed($validated['barcode'] ?? null);
        if ($barcode !== null) {
            McuIdentifier::query()->firstOrCreate(
                [
                    'mcu_id' => $mcu->id,
                    'identifier_type' => 'barcode',
                    'identifier_value' => $barcode,
                    'channel' => '',
                    'marketplace' => '',
                    'region' => '',
                ],
                [
                    'is_projection_identifier' => false,
                ]
            );
        }

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

    public function storeIdentifier(Request $request, Mcu $mcu): RedirectResponse
    {
        $validated = $this->validateIdentifier($request);
        $identifier = $this->normalizeIdentifierPayload($validated);

        if ($identifier['identifier_type'] === 'asin') {
            $this->assertAsinAvailable($mcu, $identifier['identifier_value']);
        }

        McuIdentifier::query()->create(array_merge($identifier, [
            'mcu_id' => $mcu->id,
        ]));

        return back()->with('status', 'MCU identifier added.');
    }

    public function updateIdentifier(Request $request, Mcu $mcu, McuIdentifier $identifier): RedirectResponse
    {
        if ((int) $identifier->mcu_id !== (int) $mcu->id) {
            abort(404);
        }

        $validated = $this->validateIdentifier($request);
        $payload = $this->normalizeIdentifierPayload($validated);

        if ($payload['identifier_type'] === 'asin') {
            $this->assertAsinAvailable($mcu, $payload['identifier_value'], $identifier->id);
        }

        $identifier->update($payload);

        return back()->with('status', 'MCU identifier updated.');
    }

    public function destroyIdentifier(Mcu $mcu, McuIdentifier $identifier): RedirectResponse
    {
        if ((int) $identifier->mcu_id !== (int) $mcu->id) {
            abort(404);
        }

        $identifier->delete();

        return back()->with('status', 'MCU identifier removed.');
    }

    private function validateProjection(Request $request): array
    {
        return $request->validate([
            'channel' => ['required', 'in:amazon,woocommerce,other'],
            'marketplace' => ['required', 'string', 'max:32'],
            'parent_asin' => ['nullable', 'string', 'max:32'],
            'child_asin' => ['nullable', 'string', 'max:32'],
            'seller_sku' => ['required', 'string', 'max:128'],
            'external_product_id' => ['nullable', 'string', 'max:191'],
            'fnsku' => ['nullable', 'string', 'max:64'],
            'fulfilment_type' => ['nullable', 'in:FBA,MFN'],
            'fulfilment_region' => ['nullable', 'in:EU,NA,FE'],
            'active' => ['nullable', 'boolean'],
        ]);
    }

    private function validateIdentifier(Request $request): array
    {
        return $request->validate([
            'identifier_type' => ['required', 'in:asin,seller_sku,fnsku,barcode,cost_identifier,other'],
            'identifier_value' => ['required', 'string', 'max:191'],
            'channel' => ['nullable', 'string', 'max:32'],
            'marketplace' => ['nullable', 'string', 'max:32'],
            'region' => ['nullable', 'string', 'max:16'],
            'is_projection_identifier' => ['nullable', 'boolean'],
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

    private function normalizeIdentifierPayload(array $validated): array
    {
        $type = trim((string) $validated['identifier_type']);
        $value = trim((string) $validated['identifier_value']);
        if (in_array($type, ['asin', 'fnsku'], true)) {
            $value = strtoupper($value);
        }

        return [
            'identifier_type' => $type,
            'identifier_value' => $value,
            'channel' => trim((string) ($validated['channel'] ?? '')),
            'marketplace' => trim((string) ($validated['marketplace'] ?? '')),
            'region' => trim((string) ($validated['region'] ?? '')),
            'is_projection_identifier' => !empty($validated['is_projection_identifier']),
            'asin_unique' => $type === 'asin' ? $value : null,
        ];
    }

    private function assertAsinAvailable(Mcu $mcu, string $asin, ?int $ignoreIdentifierId = null): void
    {
        $query = McuIdentifier::query()
            ->where('identifier_type', 'asin')
            ->where('asin_unique', $asin)
            ->where('mcu_id', '!=', $mcu->id);

        if ($ignoreIdentifierId !== null) {
            $query->where('id', '!=', $ignoreIdentifierId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'identifier_value' => 'ASIN is already assigned to another MCU.',
            ]);
        }
    }

    private function syncProjectionIdentifiers(
        Mcu $mcu,
        string $channel,
        string $marketplace,
        string $childAsin,
        string $sellerSku,
        string $fnsku
    ): void {
        if ($childAsin !== '') {
            McuIdentifier::query()->updateOrCreate(
                [
                    'identifier_type' => 'asin',
                    'asin_unique' => $childAsin,
                ],
                [
                    'mcu_id' => $mcu->id,
                    'identifier_value' => $childAsin,
                    'channel' => $channel,
                    'marketplace' => $marketplace,
                    'region' => '',
                    'is_projection_identifier' => true,
                ]
            );
        }

        if ($sellerSku !== '') {
            McuIdentifier::query()->firstOrCreate(
                [
                    'mcu_id' => $mcu->id,
                    'identifier_type' => 'seller_sku',
                    'identifier_value' => $sellerSku,
                    'channel' => $channel,
                    'marketplace' => $marketplace,
                    'region' => '',
                ],
                [
                    'is_projection_identifier' => true,
                ]
            );
        }

        if ($fnsku !== '') {
            McuIdentifier::query()->firstOrCreate(
                [
                    'mcu_id' => $mcu->id,
                    'identifier_type' => 'fnsku',
                    'identifier_value' => $fnsku,
                    'channel' => $channel,
                    'marketplace' => $marketplace,
                    'region' => '',
                ],
                [
                    'is_projection_identifier' => true,
                ]
            );
        }
    }
}
