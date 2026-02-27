<?php

namespace App\Services;

use App\Models\CostContext;
use App\Models\MarketplaceProjection;
use Carbon\CarbonInterface;

class MarginCalculator
{
    public function calculate(MarketplaceProjection $projection, float $salePrice, array $fees, CarbonInterface|string $saleDate): array
    {
        $saleDateValue = $saleDate instanceof CarbonInterface
            ? $saleDate->toDateString()
            : (string) $saleDate;

        $mcu = $projection->sellableUnit?->mcu;
        $region = strtoupper(trim((string) $projection->fulfilment_region));

        $context = null;
        if ($mcu && $region !== '') {
            $context = CostContext::query()
                ->where('mcu_id', $mcu->id)
                ->where('region', $region)
                ->where('effective_from', '<=', $saleDateValue)
                ->orderByDesc('effective_from')
                ->first();
        }

        $landedCost = (float) ($context?->landed_cost_per_unit ?? 0.0);
        $referralFee = (float) ($fees['referral_fee'] ?? 0.0);
        $fulfilmentFee = (float) ($fees['fulfilment_fee'] ?? 0.0);
        $marginAmount = $salePrice - $landedCost - $referralFee - $fulfilmentFee;

        return [
            'projection_id' => $projection->id,
            'mcu_id' => $mcu?->id,
            'region' => $region,
            'currency' => $context?->currency,
            'sale_date' => $saleDateValue,
            'sale_price' => round($salePrice, 4),
            'landed_cost_per_unit' => round($landedCost, 4),
            'referral_fee' => round($referralFee, 4),
            'fulfilment_fee' => round($fulfilmentFee, 4),
            'margin_amount' => round($marginAmount, 4),
            'margin_percent' => $salePrice > 0 ? round(($marginAmount / $salePrice) * 100, 2) : null,
            'cost_context_id' => $context?->id,
            'cost_effective_from' => $context?->effective_from?->toDateString(),
        ];
    }
}
