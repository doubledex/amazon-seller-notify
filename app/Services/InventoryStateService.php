<?php

namespace App\Services;

use App\Models\InventoryState;
use App\Models\MarketplaceProjection;

class InventoryStateService
{
    public function upsertState(int $mcuId, string $location, int $onHand, int $reserved = 0, int $safetyBuffer = 0): InventoryState
    {
        $cleanLocation = trim($location);

        return InventoryState::query()->updateOrCreate(
            [
                'mcu_id' => $mcuId,
                'location' => $cleanLocation,
            ],
            [
                'on_hand' => max(0, $onHand),
                'reserved' => max(0, $reserved),
                'safety_buffer' => max(0, $safetyBuffer),
                'updated_at' => now(),
            ]
        );
    }

    public function computeMfnQuantityForProjection(MarketplaceProjection $projection): int
    {
        $mcu = $projection->mcu ?: $projection->sellableUnit?->mcu;
        if (!$mcu) {
            return 0;
        }

        $states = InventoryState::query()
            ->where('mcu_id', $mcu->id)
            ->get();

        $available = 0;
        foreach ($states as $state) {
            $location = strtolower(trim((string) $state->location));
            if (str_starts_with($location, 'fba_')) {
                continue;
            }
            $available += ((int) $state->on_hand - (int) $state->reserved - (int) $state->safety_buffer);
        }

        return max(0, $available);
    }
}
