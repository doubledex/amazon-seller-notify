<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;

class OrderNetValueService
{
    public function valuesFromApiItem(array $item): array
    {
        $itemPrice = $this->amount($item, 'ItemPrice');
        $shippingPrice = $this->amount($item, 'ShippingPrice');
        $promotionDiscount = $this->amount($item, 'PromotionDiscount');
        $shippingDiscount = $this->amount($item, 'ShippingDiscount');

        if ($itemPrice === null && $shippingPrice === null && $promotionDiscount === null && $shippingDiscount === null) {
            return [
                'line_net_ex_tax' => null,
                'line_net_currency' => $this->currency($item),
            ];
        }

        // SP-API getOrderItems monetary amounts are line-level totals.
        $lineBase = (float) ($itemPrice ?? 0) + (float) ($shippingPrice ?? 0);
        $lineDiscounts = (float) ($promotionDiscount ?? 0) + (float) ($shippingDiscount ?? 0);
        $lineNet = $lineBase - $lineDiscounts;

        return [
            'line_net_ex_tax' => round(max(0.0, $lineNet), 2),
            'line_net_currency' => $this->currency($item),
        ];
    }

    public function refreshOrderNet(string $orderId): void
    {
        $totals = OrderItem::query()
            ->where('amazon_order_id', $orderId)
            ->selectRaw('line_net_currency, SUM(COALESCE(line_net_ex_tax, 0)) as total')
            ->groupBy('line_net_currency')
            ->get();

        $sum = 0.0;
        $chosenCurrency = null;

        foreach ($totals as $row) {
            $amount = (float) ($row->total ?? 0);
            if ($amount > 0) {
                $sum += $amount;
                if ($chosenCurrency === null && !empty($row->line_net_currency)) {
                    $chosenCurrency = strtoupper(trim((string) $row->line_net_currency));
                }
            }
        }

        if ($sum <= 0) {
            Order::query()
                ->where('amazon_order_id', $orderId)
                ->update([
                    'order_net_ex_tax' => null,
                    'order_net_ex_tax_currency' => null,
                    'order_net_ex_tax_source' => null,
                ]);
            return;
        }

        if ($chosenCurrency === null) {
            $chosenCurrency = Order::query()
                ->where('amazon_order_id', $orderId)
                ->value('order_total_currency');
            $chosenCurrency = strtoupper(trim((string) $chosenCurrency)) ?: null;
        }

        Order::query()
            ->where('amazon_order_id', $orderId)
            ->update([
                'order_net_ex_tax' => round($sum, 2),
                'order_net_ex_tax_currency' => $chosenCurrency,
                'order_net_ex_tax_source' => 'line_items',
            ]);
    }

    private function amount(array $item, string $key): ?float
    {
        $raw = $item[$key]['Amount'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        return (float) $raw;
    }

    private function currency(array $item): ?string
    {
        $candidates = [
            $item['ItemPrice']['CurrencyCode'] ?? null,
            $item['ShippingPrice']['CurrencyCode'] ?? null,
            $item['PromotionDiscount']['CurrencyCode'] ?? null,
            $item['ShippingDiscount']['CurrencyCode'] ?? null,
        ];

        foreach ($candidates as $currency) {
            $currency = strtoupper(trim((string) $currency));
            if ($currency !== '') {
                return $currency;
            }
        }

        return null;
    }
}
