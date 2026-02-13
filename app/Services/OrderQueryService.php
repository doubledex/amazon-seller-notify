<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Request;

class OrderQueryService
{
    public function buildQuery(Request $request, array $countries)
    {
        $query = Order::query();

        $createdAfterInput = $request->input('created_after');
        $createdBeforeInput = $request->input('created_before');
        $hasCreatedAfter = is_string($createdAfterInput) && trim($createdAfterInput) !== '';
        $hasCreatedBefore = is_string($createdBeforeInput) && trim($createdBeforeInput) !== '';

        if (!$hasCreatedAfter && !$hasCreatedBefore) {
            $createdAfterInput = now()->subDays(7)->format('Y-m-d');
            $createdBeforeInput = now()->format('Y-m-d');
            $hasCreatedAfter = true;
            $hasCreatedBefore = true;
        }

        if ($hasCreatedAfter || $hasCreatedBefore) {
            $createdAfter = $hasCreatedAfter
                ? $this->normalizeCreatedAfter($createdAfterInput)
                : $this->normalizeCreatedAfter(now()->subDays(7)->format('Y-m-d'));
            $createdBefore = $hasCreatedBefore
                ? $this->normalizeCreatedBefore($createdBeforeInput)
                : $this->normalizeCreatedBefore(now()->format('Y-m-d'));
            $startDate = (new \DateTime($createdAfter))->format('Y-m-d H:i:s');
            $endDate = (new \DateTime($createdBefore))->format('Y-m-d H:i:s');
            $query->whereBetween('purchase_date', [$startDate, $endDate]);
        }

        $selectedCountries = $request->input('countries', []);
        if (!empty($selectedCountries)) {
            $selectedMarketplaceIds = [];
            foreach ($selectedCountries as $countryCode) {
                if (isset($countries[$countryCode])) {
                    $selectedMarketplaceIds = array_merge(
                        $selectedMarketplaceIds,
                        $countries[$countryCode]['marketplaceIds']
                    );
                }
            }
            $selectedMarketplaceIds = array_values(array_unique($selectedMarketplaceIds));
            if (!empty($selectedMarketplaceIds)) {
                $query->whereIn('marketplace_id', $selectedMarketplaceIds);
            }
        }

        $selectedStatus = $request->input('status');
        if (!empty($selectedStatus)) {
            $query->where('order_status', $selectedStatus);
        }

        $selectedNetwork = $request->input('network');
        if (!empty($selectedNetwork)) {
            $query->where('fulfillment_channel', $selectedNetwork);
        }

        $selectedMethod = $request->input('method');
        if (!empty($selectedMethod)) {
            $query->where('payment_method', $selectedMethod);
        }

        $selectedB2b = $request->input('b2b');
        if (!empty($selectedB2b)) {
            $query->where('is_business_order', $selectedB2b === '1');
        }

        return $query;
    }

    public function normalizeCreatedAfter(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return now()->subDays(7)->format('Y-m-d\TH:i:s\Z');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            return $trimmed . 'T00:00:00Z';
        }

        try {
            return (new \DateTime($trimmed))->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception $e) {
            return now()->subDays(7)->format('Y-m-d\TH:i:s\Z');
        }
    }

    public function normalizeCreatedBefore(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return now()->subMinutes(2)->format('Y-m-d\TH:i:s\Z');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            return $trimmed . 'T23:59:59Z';
        }

        try {
            return (new \DateTime($trimmed))->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception $e) {
            return now()->subMinutes(2)->format('Y-m-d\TH:i:s\Z');
        }
    }
}
