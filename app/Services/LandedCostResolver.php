<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LandedCostResolver
{
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

        $resolved = $this->resolveItemContexts($contexts);

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

            $resolvedItem = $resolved[(string) ($context['context_key'] ?? '')] ?? null;
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
}
