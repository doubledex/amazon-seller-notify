<?php

namespace App\Services;

use App\Models\Family;
use App\Models\MarketplaceProjection;
use App\Models\Mcu;
use App\Models\McuIdentifier;
use App\Models\SellableUnit;
use Illuminate\Support\Facades\DB;

class ProductProjectionBootstrapService
{
    public function bootstrapFromAmazonListing(array $payload): ?MarketplaceProjection
    {
        $marketplace = trim((string) ($payload['marketplace'] ?? ''));
        $childAsin = strtoupper(trim((string) ($payload['child_asin'] ?? '')));
        $sellerSku = trim((string) ($payload['seller_sku'] ?? ''));

        if ($marketplace === '' || $childAsin === '' || $sellerSku === '') {
            return null;
        }

        $parentAsin = strtoupper(trim((string) ($payload['parent_asin'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        $fulfilmentType = strtoupper(trim((string) ($payload['fulfilment_type'] ?? 'MFN')));
        if (!in_array($fulfilmentType, ['FBA', 'MFN'], true)) {
            $fulfilmentType = 'MFN';
        }
        $fulfilmentRegion = strtoupper(trim((string) ($payload['fulfilment_region'] ?? 'EU')));
        if ($fulfilmentRegion === '') {
            $fulfilmentRegion = 'EU';
        }
        $fnsku = trim((string) ($payload['fnsku'] ?? ''));

        return DB::transaction(function () use (
            $marketplace,
            $childAsin,
            $sellerSku,
            $parentAsin,
            $name,
            $fulfilmentType,
            $fulfilmentRegion,
            $fnsku
        ) {
            $family = $this->resolveFamily($marketplace, $parentAsin, $childAsin, $name);

            $projection = MarketplaceProjection::query()
                ->where('channel', 'amazon')
                ->where('marketplace', $marketplace)
                ->where('child_asin', $childAsin)
                ->where('seller_sku', $sellerSku)
                ->first();

            if ($projection) {
                $mcu = $projection->mcu()->first();
            } else {
                $mcu = $this->resolveMcuForChildAsin($marketplace, $childAsin);
            }

            if (!$mcu) {
                $mcu = Mcu::query()->create([
                    'family_id' => $family->id,
                    'name' => $name !== '' ? $name : ('MCU ' . $childAsin),
                    'base_uom' => 'unit',
                ]);
            } elseif ($name !== '' && trim((string) $mcu->name) === '') {
                // Keep user-managed family assignment intact; enrich name only when blank.
                // Family reassignment should only happen through an explicit reconciliation flow.
                $mcu->name = $name;
                $mcu->save();
            }

            $sellableUnit = SellableUnit::query()->firstOrCreate([
                'mcu_id' => $mcu->id,
                'barcode' => $sellerSku !== '' ? $sellerSku : null,
            ]);

            $savedProjection = MarketplaceProjection::query()->updateOrCreate(
                [
                    'channel' => 'amazon',
                    'marketplace' => $marketplace,
                    'child_asin' => $childAsin,
                    'seller_sku' => $sellerSku,
                ],
                [
                    'sellable_unit_id' => $sellableUnit->id,
                    'mcu_id' => $mcu->id,
                    'parent_asin' => $parentAsin !== '' ? $parentAsin : null,
                    'fnsku' => $fnsku !== '' ? $fnsku : null,
                    'fulfilment_type' => $fulfilmentType,
                    'fulfilment_region' => $fulfilmentRegion,
                    'active' => true,
                ]
            );

            $this->upsertIdentifier($mcu, 'asin', $childAsin, 'amazon', $marketplace, '', true);
            $this->upsertIdentifier($mcu, 'seller_sku', $sellerSku, 'amazon', $marketplace, '', true);
            if ($fnsku !== '') {
                $this->upsertIdentifier($mcu, 'fnsku', strtoupper($fnsku), 'amazon', $marketplace, '', true);
            }

            return $savedProjection;
        });
    }

    private function resolveFamily(string $marketplace, string $parentAsin, string $childAsin, string $name): Family
    {
        $normalizedParent = $parentAsin !== '' ? $parentAsin : null;

        if ($normalizedParent !== null) {
            return Family::query()->firstOrCreate(
                [
                    'marketplace' => $marketplace,
                    'parent_asin' => $normalizedParent,
                ],
                [
                    'name' => $name !== '' ? $name : ('Family ' . $normalizedParent),
                ]
            );
        }

        return Family::query()->firstOrCreate(
            [
                'marketplace' => $marketplace,
                'parent_asin' => null,
                'name' => 'Family ' . $childAsin,
            ]
        );
    }

    private function resolveMcuForChildAsin(string $marketplace, string $childAsin): ?Mcu
    {
        $ownedAsin = McuIdentifier::query()
            ->where('identifier_type', 'asin')
            ->where('asin_unique', $childAsin)
            ->first();

        if ($ownedAsin) {
            return Mcu::query()->find($ownedAsin->mcu_id);
        }

        $existingProjection = MarketplaceProjection::query()
            ->where('channel', 'amazon')
            ->where('marketplace', $marketplace)
            ->where('child_asin', $childAsin)
            ->first();

        if (!$existingProjection) {
            return null;
        }

        if (!empty($existingProjection->mcu_id)) {
            return Mcu::query()->find($existingProjection->mcu_id);
        }

        $sellableUnit = SellableUnit::query()->find($existingProjection->sellable_unit_id);

        return $sellableUnit?->mcu;
    }

    private function upsertIdentifier(
        Mcu $mcu,
        string $type,
        string $value,
        string $channel,
        string $marketplace,
        string $region,
        bool $isProjectionIdentifier
    ): void {
        if ($value === '') {
            return;
        }

        $payload = [
            'mcu_id' => $mcu->id,
            'identifier_type' => $type,
            'identifier_value' => $value,
            'channel' => $channel,
            'marketplace' => $marketplace,
            'region' => $region,
            'is_projection_identifier' => $isProjectionIdentifier,
            'asin_unique' => $type === 'asin' ? $value : null,
        ];

        if ($type === 'asin') {
            McuIdentifier::query()->updateOrCreate(
                ['identifier_type' => 'asin', 'asin_unique' => $value],
                $payload
            );
            return;
        }

        McuIdentifier::query()->firstOrCreate(
            [
                'mcu_id' => $mcu->id,
                'identifier_type' => $type,
                'identifier_value' => $value,
                'channel' => $channel,
                'marketplace' => $marketplace,
                'region' => $region,
            ],
            [
                'is_projection_identifier' => $isProjectionIdentifier,
            ]
        );
    }
}
