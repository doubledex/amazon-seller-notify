<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;

class OrderNetValueService
{
    private const VAT_RATE_BY_COUNTRY = [
        'AT' => 0.20,
        'BE' => 0.21,
        'DE' => 0.19,
        'DK' => 0.25,
        'ES' => 0.21,
        'FI' => 0.24,
        'FR' => 0.20,
        'GB' => 0.20,
        'IE' => 0.23,
        'IT' => 0.22,
        'NL' => 0.21,
        'PL' => 0.23,
        'SE' => 0.25,
    ];

    public function valuesFromApiItem(array $item, ?string $marketplaceCountryCode = null, ?string $orderStatus = null): array
    {
        $isFullyShipped = strtoupper(trim((string) $orderStatus)) === 'SHIPPED';
        if ($isFullyShipped) {
            $proceedsItemSubtotalAmount = $this->proceedsItemSubtotalAmount($item);
            $proceedsItemSubtotalCurrency = $this->proceedsItemSubtotalCurrency($item);
            if ($proceedsItemSubtotalAmount === null) {
                return [
                    'line_net_ex_tax' => null,
                    'line_net_currency' => $this->currency($item),
                    'estimated_line_net_ex_tax' => null,
                    'estimated_line_currency' => null,
                ];
            }
            return [
                'line_net_ex_tax' => round(max(0.0, $proceedsItemSubtotalAmount), 2),
                'line_net_currency' => $proceedsItemSubtotalCurrency ?: $this->currency($item),
                'estimated_line_net_ex_tax' => null,
                'estimated_line_currency' => null,
            ];
        }

        $unitPriceAmount = $this->unitPriceAmount($item);
        if ($unitPriceAmount !== null) {
            $quantityOrdered = $this->intValue($item['QuantityOrdered'] ?? null);
            $quantity = max(1, $quantityOrdered ?? 1);
            $gross = $unitPriceAmount * $quantity;
            $rate = $this->marketplaceTaxRate($marketplaceCountryCode);
            $exTax = $this->removeTaxFromGross($gross, $rate);
            return [
                'line_net_ex_tax' => null,
                'line_net_currency' => null,
                'estimated_line_net_ex_tax' => round(max(0.0, $exTax), 2),
                'estimated_line_currency' => $this->unitPriceCurrency($item) ?: $this->currency($item),
            ];
        }

        // Legacy fallback for older payload shapes.
        $itemPrice = $this->amount($item, 'ItemPrice');
        $shippingPrice = $this->amount($item, 'ShippingPrice');
        $giftWrapPrice = $this->amount($item, 'GiftWrapPrice');
        $codFee = $this->amount($item, 'CODFee');

        $itemTax = $this->amount($item, 'ItemTax');
        $shippingTax = $this->amount($item, 'ShippingTax');
        $giftWrapTax = $this->amount($item, 'GiftWrapTax');
        $codFeeTax = $this->amount($item, 'CODFeeTax');
        if ($codFeeTax === null) {
            $codFeeTax = $this->amount($item, 'CODFeeDiscountTax');
        }

        $promotionDiscount = $this->amount($item, 'PromotionDiscount');
        $shippingDiscount = $this->amount($item, 'ShippingDiscount');
        $giftWrapDiscount = $this->amount($item, 'GiftWrapTaxDiscount');

        $promotionDiscountTax = $this->amount($item, 'PromotionDiscountTax');
        $shippingDiscountTax = $this->amount($item, 'ShippingDiscountTax');

        if (
            $itemPrice === null
            && $shippingPrice === null
            && $giftWrapPrice === null
            && $codFee === null
            && $itemTax === null
            && $shippingTax === null
            && $giftWrapTax === null
            && $codFeeTax === null
            && $promotionDiscount === null
            && $shippingDiscount === null
            && $giftWrapDiscount === null
            && $promotionDiscountTax === null
            && $shippingDiscountTax === null
        ) {
            return [
                'line_net_ex_tax' => null,
                'line_net_currency' => $this->currency($item),
                'estimated_line_net_ex_tax' => null,
                'estimated_line_currency' => null,
            ];
        }

        // In NA marketplaces, item price fields are already tax-exclusive for our use case.
        if ($this->isNaMarketplace($marketplaceCountryCode)) {
            $lineBase = (float) ($itemPrice ?? 0)
                + (float) ($shippingPrice ?? 0)
                + (float) ($giftWrapPrice ?? 0)
                + (float) ($codFee ?? 0);
            $lineDiscounts = (float) ($promotionDiscount ?? 0)
                + (float) ($shippingDiscount ?? 0)
                + (float) ($giftWrapDiscount ?? 0);
            $lineNet = $lineBase - $lineDiscounts;

            return [
                'line_net_ex_tax' => round(max(0.0, $lineNet), 2),
                'line_net_currency' => $this->currency($item),
                'estimated_line_net_ex_tax' => null,
                'estimated_line_currency' => null,
            ];
        }

        // For VAT marketplaces, derive ex-tax by removing tax components from line totals.
        $grossPositive = (float) ($itemPrice ?? 0)
            + (float) ($shippingPrice ?? 0)
            + (float) ($giftWrapPrice ?? 0)
            + (float) ($codFee ?? 0);
        $grossNegative = (float) ($promotionDiscount ?? 0)
            + (float) ($shippingDiscount ?? 0)
            + (float) ($giftWrapDiscount ?? 0);
        $grossLine = $grossPositive - $grossNegative;

        $taxPositive = (float) ($itemTax ?? 0)
            + (float) ($shippingTax ?? 0)
            + (float) ($giftWrapTax ?? 0)
            + (float) ($codFeeTax ?? 0);
        $taxNegative = (float) ($promotionDiscountTax ?? 0)
            + (float) ($shippingDiscountTax ?? 0);
        $lineTax = $taxPositive - $taxNegative;

        $lineNet = $grossLine - $lineTax;

        return [
            'line_net_ex_tax' => round(max(0.0, $lineNet), 2),
            'line_net_currency' => $this->currency($item),
            'estimated_line_net_ex_tax' => null,
            'estimated_line_currency' => null,
        ];
    }

    private function isNaMarketplace(?string $marketplaceCountryCode): bool
    {
        $country = strtoupper(trim((string) $marketplaceCountryCode));
        return in_array($country, ['US', 'CA', 'MX', 'BR'], true);
    }

    public function refreshOrderNet(string $orderId): void
    {
        $lineTotals = OrderItem::query()
            ->where('amazon_order_id', $orderId)
            ->selectRaw('line_net_currency, SUM(COALESCE(line_net_ex_tax, 0)) as total')
            ->groupBy('line_net_currency')
            ->get();
        $estimatedTotals = OrderItem::query()
            ->where('amazon_order_id', $orderId)
            ->selectRaw('estimated_line_currency, SUM(COALESCE(estimated_line_net_ex_tax, 0)) as total')
            ->groupBy('estimated_line_currency')
            ->get();

        $sum = 0.0;
        $chosenCurrency = null;
        $source = null;

        foreach ($lineTotals as $row) {
            $amount = (float) ($row->total ?? 0);
            if ($amount > 0) {
                $sum += $amount;
                if ($chosenCurrency === null && !empty($row->line_net_currency)) {
                    $chosenCurrency = strtoupper(trim((string) $row->line_net_currency));
                }
            }
        }

        if ($sum > 0) {
            $source = 'proceeds_item_subtotal';
        } else {
            foreach ($estimatedTotals as $row) {
                $amount = (float) ($row->total ?? 0);
                if ($amount > 0) {
                    $sum += $amount;
                    if ($chosenCurrency === null && !empty($row->estimated_line_currency)) {
                        $chosenCurrency = strtoupper(trim((string) $row->estimated_line_currency));
                    }
                }
            }
            if ($sum > 0) {
                $source = 'est_unit_minus_tax';
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
                'order_net_ex_tax_source' => $source,
            ]);
    }

    private function proceedsItemSubtotalAmount(array $item): ?float
    {
        $breakdowns = $item['proceeds']['breakdowns']
            ?? $item['Proceeds']['Breakdowns']
            ?? [];
        if (!is_array($breakdowns)) {
            return null;
        }

        foreach ($breakdowns as $breakdown) {
            if (!is_array($breakdown)) {
                continue;
            }
            $type = strtoupper(trim((string) ($breakdown['type'] ?? $breakdown['Type'] ?? '')));
            if ($type !== 'ITEM') {
                continue;
            }
            $amount = $breakdown['subtotal']['amount']
                ?? $breakdown['Subtotal']['Amount']
                ?? null;
            if ($amount === null || $amount === '') {
                return null;
            }

            return (float) $amount;
        }

        return null;
    }

    private function proceedsItemSubtotalCurrency(array $item): ?string
    {
        $breakdowns = $item['proceeds']['breakdowns']
            ?? $item['Proceeds']['Breakdowns']
            ?? [];
        if (!is_array($breakdowns)) {
            return null;
        }

        foreach ($breakdowns as $breakdown) {
            if (!is_array($breakdown)) {
                continue;
            }
            $type = strtoupper(trim((string) ($breakdown['type'] ?? $breakdown['Type'] ?? '')));
            if ($type !== 'ITEM') {
                continue;
            }
            $currency = $breakdown['subtotal']['currencyCode']
                ?? $breakdown['Subtotal']['CurrencyCode']
                ?? null;
            $currency = strtoupper(trim((string) $currency));

            return $currency !== '' ? $currency : null;
        }

        return null;
    }

    private function marketplaceTaxRate(?string $marketplaceCountryCode): float
    {
        $country = strtoupper(trim((string) $marketplaceCountryCode));
        return (float) (self::VAT_RATE_BY_COUNTRY[$country] ?? 0.0);
    }

    private function removeTaxFromGross(float $gross, float $taxRate): float
    {
        if ($taxRate <= 0) {
            return $gross;
        }

        return $gross / (1 + $taxRate);
    }

    private function unitPriceAmount(array $item): ?float
    {
        $raw = $item['product']['price']['unitPrice']['amount']
            ?? $item['Product']['Price']['UnitPrice']['Amount']
            ?? $item['ItemPrice']['Amount']
            ?? null;
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    private function unitPriceCurrency(array $item): ?string
    {
        $raw = $item['product']['price']['unitPrice']['currencyCode']
            ?? $item['Product']['Price']['UnitPrice']['CurrencyCode']
            ?? $item['ItemPrice']['CurrencyCode']
            ?? null;
        $currency = strtoupper(trim((string) $raw));
        return $currency !== '' ? $currency : null;
    }

    private function intValue(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
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
