<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LandedCostResolver
{
    private const UK_COUNTRY_CODES = ['GB', 'UK'];
    private const EU_COUNTRY_CODES = ['AT', 'BE', 'DE', 'DK', 'ES', 'FI', 'FR', 'IE', 'IT', 'LU', 'NL', 'NO', 'PL', 'SE', 'CH', 'TR'];
    private const NA_COUNTRY_CODES = ['US', 'CA', 'MX', 'BR'];

    /**
     * @param array<int,string> $orderIds
     * @return array<string,array<string,mixed>>
     */
    public function resolveOrderLandedCosts(array $orderIds): array
    {
        $orderIds = array_values(array_filter(array_map(static fn ($v) => trim((string) $v), $orderIds)));
        if (empty($orderIds)) {
            return [];
        }

        $rows = DB::table('order_items')
            ->join('orders', 'orders.amazon_order_id', '=', 'order_items.amazon_order_id')
            ->select([
                'order_items.id as item_id',
                'order_items.amazon_order_id',
                'order_items.asin',
                'order_items.seller_sku',
                'order_items.quantity_ordered',
                'order_items.quantity_shipped',
                'orders.marketplace_id',
            ])
            ->selectRaw("COALESCE(orders.purchase_date_local_date, DATE(orders.purchase_date)) as item_date")
            ->whereIn('order_items.amazon_order_id', $orderIds)
            ->get();

        $contexts = [];
        foreach ($rows as $row) {
            $contexts[] = [
                'context_key' => 'item:' . (string) $row->item_id,
                'amazon_order_id' => (string) ($row->amazon_order_id ?? ''),
                'asin' => (string) ($row->asin ?? ''),
                'seller_sku' => (string) ($row->seller_sku ?? ''),
                'marketplace_id' => $row->marketplace_id,
                'effective_date' => (string) ($row->item_date ?? ''),
                'quantity' => $this->extractQuantity((int) ($row->quantity_ordered ?? 0), (int) ($row->quantity_shipped ?? 0)),
            ];
        }

        $resolvedMcu = $this->resolveMcuItemContexts($contexts);
        $resolvedFallback = $this->resolveItemContexts($contexts);

        $totals = [];
        foreach ($contexts as $context) {
            $orderId = (string) ($context['amazon_order_id'] ?? '');
            if ($orderId === '') {
                continue;
            }
            if (!isset($totals[$orderId])) {
                $totals[$orderId] = [
                    'landed_cost_total' => 0.0,
                    'currency' => null,
                    'mixed_currency' => false,
                    'resolved_items' => 0,
                    'total_items' => 0,
                ];
            }

            $totals[$orderId]['total_items']++;

            $contextKey = (string) ($context['context_key'] ?? '');
            $resolvedItem = $resolvedMcu[$contextKey] ?? $resolvedFallback[$contextKey] ?? null;
            if (!is_array($resolvedItem) || !isset($resolvedItem['unit_landed_cost'])) {
                continue;
            }

            $lineCost = (float) ($resolvedItem['unit_landed_cost'] ?? 0) * (int) ($context['quantity'] ?? 0);
            $currency = strtoupper(trim((string) ($resolvedItem['currency'] ?? '')));

            $totals[$orderId]['landed_cost_total'] += $lineCost;
            $totals[$orderId]['resolved_items']++;
            if ($currency !== '') {
                if ($totals[$orderId]['currency'] === null) {
                    $totals[$orderId]['currency'] = $currency;
                } elseif ($totals[$orderId]['currency'] !== $currency) {
                    $totals[$orderId]['mixed_currency'] = true;
                }
            }
        }

        foreach ($totals as $orderId => $row) {
            $totals[$orderId]['landed_cost_total'] = round((float) $row['landed_cost_total'], 2);
            if ($totals[$orderId]['mixed_currency']) {
                $totals[$orderId]['currency'] = 'MIXED';
            }
        }

        return $totals;
    }

    /**
     * Resolve landed costs in each order's local/net currency.
     * FX conversion uses cost-line effective_from date.
     *
     * @param array<int,string> $orderIds
     * @return array<string,array<string,mixed>>
     */
    public function resolveOrderLandedCostsForOrderCurrency(array $orderIds): array
    {
        $orderIds = array_values(array_filter(array_map(static fn ($v) => trim((string) $v), $orderIds)));
        if (empty($orderIds)) {
            return [];
        }

        $rows = DB::table('order_items')
            ->join('orders', 'orders.amazon_order_id', '=', 'order_items.amazon_order_id')
            ->leftJoin('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->select([
                'order_items.id as item_id',
                'order_items.amazon_order_id',
                'order_items.asin',
                'order_items.seller_sku',
                'order_items.quantity_ordered',
                'order_items.quantity_shipped',
                'orders.marketplace_id',
            ])
            ->selectRaw("COALESCE(orders.purchase_date_local_date, DATE(orders.purchase_date)) as item_date")
            ->selectRaw("COALESCE(NULLIF(orders.order_net_ex_tax_currency, ''), NULLIF(orders.order_total_currency, ''), NULLIF(marketplaces.default_currency, '')) as target_currency")
            ->whereIn('order_items.amazon_order_id', $orderIds)
            ->get();

        $contexts = [];
        foreach ($rows as $row) {
            $contexts[] = [
                'context_key' => 'item:' . (string) $row->item_id,
                'amazon_order_id' => (string) ($row->amazon_order_id ?? ''),
                'asin' => (string) ($row->asin ?? ''),
                'seller_sku' => (string) ($row->seller_sku ?? ''),
                'marketplace_id' => $row->marketplace_id,
                'effective_date' => (string) ($row->item_date ?? ''),
                'target_currency' => strtoupper(trim((string) ($row->target_currency ?? ''))),
                'quantity' => $this->extractQuantity((int) ($row->quantity_ordered ?? 0), (int) ($row->quantity_shipped ?? 0)),
            ];
        }

        $resolvedMcu = $this->resolveMcuItemContexts($contexts, true);
        $resolvedFallback = $this->resolveItemContexts($contexts);
        $fxService = new FxRateService();

        $totals = [];
        foreach ($contexts as $context) {
            $orderId = (string) ($context['amazon_order_id'] ?? '');
            if ($orderId === '') {
                continue;
            }
            if (!isset($totals[$orderId])) {
                $totals[$orderId] = [
                    'landed_cost_total' => 0.0,
                    'currency' => null,
                    'mixed_currency' => false,
                    'resolved_items' => 0,
                    'total_items' => 0,
                ];
            }

            $totals[$orderId]['total_items']++;
            $targetCurrency = strtoupper(trim((string) ($context['target_currency'] ?? '')));
            $contextKey = (string) ($context['context_key'] ?? '');
            $resolvedItem = $resolvedMcu[$contextKey] ?? $resolvedFallback[$contextKey] ?? null;
            if (!is_array($resolvedItem)) {
                continue;
            }

            $lineCost = $this->resolveLineCostInTargetCurrency($resolvedItem, $context, $targetCurrency, $fxService);
            if ($lineCost === null) {
                continue;
            }

            $totals[$orderId]['landed_cost_total'] += $lineCost;
            $totals[$orderId]['resolved_items']++;
            if ($targetCurrency !== '') {
                if ($totals[$orderId]['currency'] === null) {
                    $totals[$orderId]['currency'] = $targetCurrency;
                } elseif ($totals[$orderId]['currency'] !== $targetCurrency) {
                    $totals[$orderId]['mixed_currency'] = true;
                }
            }
        }

        foreach ($totals as $orderId => $row) {
            $totals[$orderId]['landed_cost_total'] = round((float) $row['landed_cost_total'], 2);
            if ($totals[$orderId]['mixed_currency']) {
                $totals[$orderId]['currency'] = 'MIXED';
            }
        }

        return $totals;
    }

    /**
     * @param array<int,array<string,mixed>> $contexts
     * @return array<string,array<string,mixed>> keyed by context_key
     */
    public function resolveItemContexts(array $contexts): array
    {
        $normalized = [];
        $asins = [];
        $skus = [];
        $marketplaceIds = [];

        foreach ($contexts as $context) {
            $key = trim((string) ($context['context_key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $asin = strtoupper(trim((string) ($context['asin'] ?? '')));
            $sellerSku = trim((string) ($context['seller_sku'] ?? ''));
            $marketplaceId = trim((string) ($context['marketplace_id'] ?? ''));
            $effectiveDate = trim((string) ($context['effective_date'] ?? ''));

            if ($asin === '' && $sellerSku === '') {
                continue;
            }
            if ($effectiveDate === '') {
                $effectiveDate = now()->toDateString();
            }

            $normalized[$key] = [
                'asin' => $asin,
                'seller_sku' => $sellerSku,
                'marketplace_id' => $marketplaceId,
                'effective_date' => $effectiveDate,
            ];

            if ($asin !== '') {
                $asins[] = $asin;
            }
            if ($sellerSku !== '') {
                $skus[] = $sellerSku;
            }
            if ($marketplaceId !== '') {
                $marketplaceIds[] = $marketplaceId;
            }
        }

        if (empty($normalized)) {
            return [];
        }

        $identifiers = DB::table('product_identifiers')
            ->select(['id', 'product_id', 'identifier_type', 'identifier_value', 'marketplace_id', 'is_primary'])
            ->where(function ($query) use ($asins, $skus) {
                if (!empty($asins)) {
                    $query->orWhere(function ($sub) use ($asins) {
                        $sub->where('identifier_type', 'asin')->whereIn('identifier_value', array_values(array_unique($asins)));
                    });
                }
                if (!empty($skus)) {
                    $query->orWhere(function ($sub) use ($skus) {
                        $sub->where('identifier_type', 'seller_sku')->whereIn('identifier_value', array_values(array_unique($skus)));
                    });
                }
            })
            ->where(function ($query) use ($marketplaceIds) {
                $query->whereNull('marketplace_id');
                if (!empty($marketplaceIds)) {
                    $query->orWhereIn('marketplace_id', array_values(array_unique($marketplaceIds)));
                }
            })
            ->get();

        $identifierLookup = [];
        $candidateIdentifierIds = [];
        foreach ($identifiers as $identifier) {
            $type = (string) ($identifier->identifier_type ?? '');
            $value = (string) ($identifier->identifier_value ?? '');
            if ($type === '' || $value === '') {
                continue;
            }
            $idx = $type . '|' . $value;
            $identifierLookup[$idx][] = $identifier;
            $candidateIdentifierIds[] = (int) $identifier->id;
        }

        if (empty($candidateIdentifierIds)) {
            return [];
        }

        $layers = DB::table('product_identifier_cost_layers')
            ->select(['id', 'product_identifier_id', 'effective_from', 'effective_to', 'currency', 'unit_landed_cost'])
            ->whereIn('product_identifier_id', array_values(array_unique($candidateIdentifierIds)))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $layersByIdentifier = [];
        foreach ($layers as $layer) {
            $layersByIdentifier[(int) $layer->product_identifier_id][] = $layer;
        }

        $resolved = [];
        foreach ($normalized as $key => $context) {
            $itemCandidates = [];
            if ($context['seller_sku'] !== '') {
                foreach ($identifierLookup['seller_sku|' . $context['seller_sku']] ?? [] as $identifier) {
                    $itemCandidates[(int) $identifier->id] = $identifier;
                }
            }
            if ($context['asin'] !== '') {
                foreach ($identifierLookup['asin|' . $context['asin']] ?? [] as $identifier) {
                    $itemCandidates[(int) $identifier->id] = $identifier;
                }
            }

            if (empty($itemCandidates)) {
                continue;
            }

            $best = null;
            $bestRank = null;
            foreach ($itemCandidates as $identifier) {
                $identifierId = (int) $identifier->id;
                $layersForIdentifier = $layersByIdentifier[$identifierId] ?? [];
                [$selectedLayer, $active] = $this->chooseLayer($layersForIdentifier, $context['effective_date']);
                if ($selectedLayer === null) {
                    continue;
                }

                $identifierRank = $this->identifierRank($identifier, $context);
                $rank = [
                    $active ? 1 : 0,
                    $identifierRank,
                    (string) ($selectedLayer->effective_from ?? ''),
                    (int) ($selectedLayer->id ?? 0),
                ];

                if ($bestRank === null || $this->compareRank($rank, $bestRank) > 0) {
                    $best = ['identifier' => $identifier, 'layer' => $selectedLayer];
                    $bestRank = $rank;
                }
            }

            if ($best === null) {
                continue;
            }

            $layer = $best['layer'];
            $resolved[$key] = [
                'product_identifier_id' => (int) ($best['identifier']->id ?? 0),
                'cost_layer_id' => (int) ($layer->id ?? 0),
                'unit_landed_cost' => (float) ($layer->unit_landed_cost ?? 0),
                'currency' => strtoupper(trim((string) ($layer->currency ?? ''))),
                'effective_from' => (string) ($layer->effective_from ?? ''),
                'effective_to' => (string) ($layer->effective_to ?? ''),
            ];
        }

        return $resolved;
    }

    /**
     * Resolve item contexts to MCU cost rows and return summed unit cost when a clean match exists.
     *
     * @param array<int,array<string,mixed>> $contexts
     * @return array<string,array<string,mixed>> keyed by context_key
     */
    private function resolveMcuItemContexts(array $contexts, bool $allowMixedCurrencies = false): array
    {
        $normalized = [];
        $asins = [];
        $skus = [];
        $marketplaceIds = [];
        $dates = [];

        foreach ($contexts as $context) {
            $key = trim((string) ($context['context_key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $asin = strtoupper(trim((string) ($context['asin'] ?? '')));
            $sellerSku = trim((string) ($context['seller_sku'] ?? ''));
            $marketplaceId = trim((string) ($context['marketplace_id'] ?? ''));
            $effectiveDate = trim((string) ($context['effective_date'] ?? ''));
            if ($effectiveDate === '') {
                $effectiveDate = now()->toDateString();
            }

            if ($asin === '' && $sellerSku === '') {
                continue;
            }

            $normalized[$key] = [
                'asin' => $asin,
                'seller_sku' => $sellerSku,
                'marketplace_id' => $marketplaceId,
                'effective_date' => $effectiveDate,
            ];

            if ($asin !== '') {
                $asins[] = $asin;
            }
            if ($sellerSku !== '') {
                $skus[] = $sellerSku;
            }
            if ($marketplaceId !== '') {
                $marketplaceIds[] = $marketplaceId;
            }
            $dates[] = $effectiveDate;
        }

        if (empty($normalized)) {
            return [];
        }

        $asins = array_values(array_unique($asins));
        $skus = array_values(array_unique($skus));
        $marketplaceIds = array_values(array_unique($marketplaceIds));

        $projectionRows = DB::table('marketplace_projections')
            ->select(['id', 'mcu_id', 'marketplace', 'child_asin', 'seller_sku'])
            ->where('channel', 'amazon')
            ->where(function ($query) use ($asins, $skus) {
                if (!empty($asins)) {
                    $query->orWhereIn('child_asin', $asins);
                }
                if (!empty($skus)) {
                    $query->orWhereIn('seller_sku', $skus);
                }
            })
            ->when(!empty($marketplaceIds), fn ($query) => $query->whereIn('marketplace', $marketplaceIds))
            ->get();

        $projectionByMarketplaceSku = [];
        $projectionByMarketplaceAsin = [];
        foreach ($projectionRows as $projection) {
            $projectionMarketplace = trim((string) ($projection->marketplace ?? ''));
            $projectionMcuId = (int) ($projection->mcu_id ?? 0);
            if ($projectionMarketplace === '' || $projectionMcuId <= 0) {
                continue;
            }

            $projectionSku = trim((string) ($projection->seller_sku ?? ''));
            if ($projectionSku !== '') {
                $projectionByMarketplaceSku[$projectionMarketplace . '|' . $projectionSku][] = $projection;
            }

            $projectionAsin = strtoupper(trim((string) ($projection->child_asin ?? '')));
            if ($projectionAsin !== '') {
                $projectionByMarketplaceAsin[$projectionMarketplace . '|' . $projectionAsin][] = $projection;
            }
        }

        $identifierRows = DB::table('mcu_identifiers')
            ->select(['id', 'mcu_id', 'identifier_type', 'identifier_value', 'channel', 'marketplace'])
            ->whereIn('identifier_type', ['asin', 'seller_sku'])
            ->where(function ($query) use ($asins, $skus) {
                if (!empty($asins)) {
                    $query->orWhere(function ($sub) use ($asins) {
                        $sub->where('identifier_type', 'asin')->whereIn('identifier_value', $asins);
                    });
                }
                if (!empty($skus)) {
                    $query->orWhere(function ($sub) use ($skus) {
                        $sub->where('identifier_type', 'seller_sku')->whereIn('identifier_value', $skus);
                    });
                }
            })
            ->whereIn('channel', ['amazon', ''])
            ->where(function ($query) use ($marketplaceIds) {
                $query->where('marketplace', '');
                if (!empty($marketplaceIds)) {
                    $query->orWhereIn('marketplace', $marketplaceIds);
                }
            })
            ->get();

        $identifierByTypeValue = [];
        foreach ($identifierRows as $identifier) {
            $type = trim((string) ($identifier->identifier_type ?? ''));
            $value = trim((string) ($identifier->identifier_value ?? ''));
            $mcuId = (int) ($identifier->mcu_id ?? 0);
            if ($type === '' || $value === '' || $mcuId <= 0) {
                continue;
            }
            $identifierByTypeValue[$type . '|' . $value][] = $identifier;
        }

        $mcuByContext = [];
        $mcuIds = [];
        foreach ($normalized as $contextKey => $context) {
            $marketplaceId = $context['marketplace_id'];
            $asin = $context['asin'];
            $sellerSku = $context['seller_sku'];

            $bestMcuId = null;
            $bestRank = null;

            $projectionCandidates = [];
            if ($sellerSku !== '' && $marketplaceId !== '') {
                $projectionCandidates = array_merge($projectionCandidates, $projectionByMarketplaceSku[$marketplaceId . '|' . $sellerSku] ?? []);
            }
            if ($asin !== '' && $marketplaceId !== '') {
                $projectionCandidates = array_merge($projectionCandidates, $projectionByMarketplaceAsin[$marketplaceId . '|' . $asin] ?? []);
            }

            foreach ($projectionCandidates as $projection) {
                $rank = 1000;
                if ($sellerSku !== '' && trim((string) ($projection->seller_sku ?? '')) === $sellerSku) {
                    $rank += 200;
                }
                if ($asin !== '' && strtoupper(trim((string) ($projection->child_asin ?? ''))) === $asin) {
                    $rank += 100;
                }
                $rank += (int) ($projection->id ?? 0);

                if ($bestRank === null || $rank > $bestRank) {
                    $bestRank = $rank;
                    $bestMcuId = (int) ($projection->mcu_id ?? 0);
                }
            }

            if ($bestMcuId === null) {
                $identifierCandidates = [];
                if ($sellerSku !== '') {
                    $identifierCandidates = array_merge($identifierCandidates, $identifierByTypeValue['seller_sku|' . $sellerSku] ?? []);
                }
                if ($asin !== '') {
                    $identifierCandidates = array_merge($identifierCandidates, $identifierByTypeValue['asin|' . $asin] ?? []);
                }

                foreach ($identifierCandidates as $identifier) {
                    $identifierMarketplace = trim((string) ($identifier->marketplace ?? ''));
                    if ($identifierMarketplace !== '' && $identifierMarketplace !== $marketplaceId) {
                        continue;
                    }

                    $rank = 500;
                    $rank += $identifierMarketplace === $marketplaceId && $marketplaceId !== '' ? 100 : 50;
                    $rank += (string) ($identifier->identifier_type ?? '') === 'seller_sku' ? 30 : 20;
                    $rank += (int) ($identifier->id ?? 0);

                    if ($bestRank === null || $rank > $bestRank) {
                        $bestRank = $rank;
                        $bestMcuId = (int) ($identifier->mcu_id ?? 0);
                    }
                }
            }

            if ($bestMcuId !== null && $bestMcuId > 0) {
                $mcuByContext[$contextKey] = $bestMcuId;
                $mcuIds[] = $bestMcuId;
            }
        }

        if (empty($mcuByContext)) {
            return [];
        }

        $mcuIds = array_values(array_unique($mcuIds));
        $maxDate = !empty($dates) ? max($dates) : now()->toDateString();
        $minDate = !empty($dates) ? min($dates) : now()->toDateString();

        $mcuCostRows = DB::table('mcu_cost_values')
            ->select(['id', 'mcu_id', 'amount', 'currency', 'effective_from', 'effective_to', 'marketplace', 'region'])
            ->whereIn('mcu_id', $mcuIds)
            ->where('effective_from', '<=', $maxDate)
            ->where(function ($query) use ($minDate) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $minDate);
            })
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();

        if ($mcuCostRows->isEmpty()) {
            return [];
        }

        $costRowsByMcu = [];
        foreach ($mcuCostRows as $row) {
            $costRowsByMcu[(int) ($row->mcu_id ?? 0)][] = $row;
        }

        $marketplaceCountries = DB::table('marketplaces')
            ->whereIn('id', $marketplaceIds)
            ->pluck('country_code', 'id')
            ->map(fn ($country) => strtoupper(trim((string) $country)))
            ->all();

        $resolved = [];
        foreach ($normalized as $contextKey => $context) {
            $mcuId = $mcuByContext[$contextKey] ?? null;
            if ($mcuId === null) {
                continue;
            }

            $contextMarketplace = $context['marketplace_id'];
            $contextDate = $context['effective_date'];
            $contextRegion = $this->regionForCountryCode($marketplaceCountries[$contextMarketplace] ?? '');

            $activeRows = [];
            foreach ($costRowsByMcu[$mcuId] ?? [] as $row) {
                $effectiveFrom = trim((string) ($row->effective_from ?? ''));
                $effectiveTo = trim((string) ($row->effective_to ?? ''));
                if ($effectiveFrom === '' || $effectiveFrom > $contextDate) {
                    continue;
                }
                if ($effectiveTo !== '' && $effectiveTo < $contextDate) {
                    continue;
                }

                $rowMarketplace = trim((string) ($row->marketplace ?? ''));
                $rowRegion = strtoupper(trim((string) ($row->region ?? '')));
                if ($rowMarketplace !== '' && $rowMarketplace !== $contextMarketplace) {
                    continue;
                }
                if ($rowRegion !== '' && $rowRegion !== $contextRegion) {
                    continue;
                }

                $activeRows[] = $row;
            }

            if (empty($activeRows)) {
                continue;
            }

            $currencyCodes = [];
            $unitLandedCost = 0.0;
            $costComponents = [];
            foreach ($activeRows as $row) {
                $currency = strtoupper(trim((string) ($row->currency ?? '')));
                if ($currency === '') {
                    continue;
                }
                $currencyCodes[$currency] = true;
                $componentAmount = (float) ($row->amount ?? 0.0);
                $unitLandedCost += $componentAmount;
                $costComponents[] = [
                    'amount' => $componentAmount,
                    'currency' => $currency,
                    'effective_from' => (string) ($row->effective_from ?? ''),
                ];
            }

            if (count($currencyCodes) !== 1 && !$allowMixedCurrencies) {
                // Default resolver path stays deterministic for legacy consumers.
                continue;
            }

            $resolved[$contextKey] = [
                'mcu_id' => $mcuId,
                'unit_landed_cost' => round($unitLandedCost, 4),
                'currency' => count($currencyCodes) === 1 ? array_key_first($currencyCodes) : 'MIXED',
                'effective_from' => !empty($costComponents) ? (string) ($costComponents[0]['effective_from'] ?? '') : '',
                'cost_components' => $costComponents,
            ];
        }

        return $resolved;
    }

    private function chooseLayer(array $layers, string $effectiveDate): array
    {
        $active = [];
        $past = [];

        foreach ($layers as $layer) {
            $from = (string) ($layer->effective_from ?? '');
            $to = (string) ($layer->effective_to ?? '');
            if ($from === '') {
                continue;
            }

            if ($from <= $effectiveDate && ($to === '' || $to >= $effectiveDate)) {
                $active[] = $layer;
                continue;
            }
            if ($from <= $effectiveDate) {
                $past[] = $layer;
            }
        }

        if (!empty($active)) {
            usort($active, function ($a, $b) {
                $fromCompare = strcmp((string) ($b->effective_from ?? ''), (string) ($a->effective_from ?? ''));
                if ($fromCompare !== 0) {
                    return $fromCompare;
                }

                $aTo = (string) ($a->effective_to ?? '9999-12-31');
                $bTo = (string) ($b->effective_to ?? '9999-12-31');
                $toCompare = strcmp($aTo, $bTo);
                if ($toCompare !== 0) {
                    return $toCompare;
                }

                return ((int) ($b->id ?? 0)) <=> ((int) ($a->id ?? 0));
            });

            return [$active[0], true];
        }

        if (!empty($past)) {
            usort($past, fn ($a, $b) => strcmp((string) ($b->effective_from ?? ''), (string) ($a->effective_from ?? '')));
            return [$past[0], false];
        }

        return [null, false];
    }

    private function identifierRank(object $identifier, array $context): int
    {
        $rank = 0;
        $marketplaceId = trim((string) ($identifier->marketplace_id ?? ''));
        if ($marketplaceId !== '' && $marketplaceId === $context['marketplace_id']) {
            $rank += 100;
        } elseif ($marketplaceId === '') {
            $rank += 50;
        }

        $type = (string) ($identifier->identifier_type ?? '');
        if ($type === 'seller_sku' && (string) ($identifier->identifier_value ?? '') === $context['seller_sku']) {
            $rank += 30;
        }
        if ($type === 'asin' && strtoupper((string) ($identifier->identifier_value ?? '')) === $context['asin']) {
            $rank += 20;
        }

        if ((bool) ($identifier->is_primary ?? false)) {
            $rank += 5;
        }

        return $rank;
    }

    private function compareRank(array $a, array $b): int
    {
        for ($i = 0; $i < count($a); $i++) {
            if (($a[$i] ?? null) === ($b[$i] ?? null)) {
                continue;
            }
            return ($a[$i] ?? 0) <=> ($b[$i] ?? 0);
        }

        return 0;
    }

    private function extractQuantity(int $quantityOrdered, int $quantityShipped): int
    {
        if ($quantityShipped > 0) {
            return $quantityShipped;
        }

        return max(0, $quantityOrdered);
    }

    private function regionForCountryCode(string $countryCode): string
    {
        $countryCode = strtoupper(trim($countryCode));
        if ($countryCode === '') {
            return '';
        }
        if (in_array($countryCode, self::UK_COUNTRY_CODES, true)) {
            return 'UK';
        }
        if (in_array($countryCode, self::EU_COUNTRY_CODES, true)) {
            return 'EU';
        }
        if (in_array($countryCode, self::NA_COUNTRY_CODES, true)) {
            return 'NA';
        }

        return '';
    }

    private function resolveLineCostInTargetCurrency(
        array $resolvedItem,
        array $context,
        string $targetCurrency,
        FxRateService $fxService
    ): ?float {
        $quantity = max(0, (int) ($context['quantity'] ?? 0));
        if ($quantity <= 0) {
            return 0.0;
        }

        $components = $resolvedItem['cost_components'] ?? null;
        if (is_array($components) && !empty($components) && $targetCurrency !== '') {
            $lineTotal = 0.0;
            foreach ($components as $component) {
                $amount = (float) ($component['amount'] ?? 0.0);
                $currency = strtoupper(trim((string) ($component['currency'] ?? '')));
                if ($currency === '') {
                    continue;
                }
                $fxDate = trim((string) ($component['effective_from'] ?? ''));
                if ($fxDate === '') {
                    $fxDate = trim((string) ($context['effective_date'] ?? now()->toDateString()));
                }
                $lineAmount = $amount * $quantity;
                if ($currency !== $targetCurrency) {
                    $converted = $fxService->convert($lineAmount, $currency, $targetCurrency, $fxDate);
                    if ($converted === null) {
                        return null;
                    }
                    $lineAmount = $converted;
                }
                $lineTotal += $lineAmount;
            }

            return $lineTotal;
        }

        if (!isset($resolvedItem['unit_landed_cost'])) {
            return null;
        }
        $lineCost = (float) ($resolvedItem['unit_landed_cost'] ?? 0.0) * $quantity;
        $currency = strtoupper(trim((string) ($resolvedItem['currency'] ?? '')));
        if ($targetCurrency === '' || $currency === '' || $currency === $targetCurrency) {
            return $lineCost;
        }

        $fxDate = trim((string) ($resolvedItem['effective_from'] ?? ''));
        if ($fxDate === '') {
            $fxDate = trim((string) ($context['effective_date'] ?? now()->toDateString()));
        }
        $converted = $fxService->convert($lineCost, $currency, $targetCurrency, $fxDate);
        if ($converted === null) {
            return null;
        }

        return $converted;
    }
}
