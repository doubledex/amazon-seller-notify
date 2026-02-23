<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use SellingPartnerApi\SellingPartnerApi;
use Saloon\Exceptions\Request\Statuses\TooManyRequestsException;
use Saloon\Http\Response;

class AmazonOrderFeeSyncService
{
    private const MAX_ATTEMPTS = 6;

    public function sync(Carbon $from, Carbon $to, ?string $region = null): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $maxPostedBefore = now()->subMinutes(2);
        if ($to->gt($maxPostedBefore)) {
            $to = $maxPostedBefore->copy();
        }
        if ($from->gte($to)) {
            $from = $to->copy()->subDay();
        }

        $regionService = new RegionConfigService();
        $regions = $region ? [strtoupper(trim($region))] : $regionService->spApiRegions();
        $regions = array_values(array_filter($regions, fn (string $r) => in_array($r, ['EU', 'NA', 'FE'], true)));

        $summary = [
            'regions' => count($regions),
            'events' => 0,
            'orders_updated' => 0,
        ];

        foreach ($regions as $configuredRegion) {
            $config = $regionService->spApiConfig($configuredRegion);
            $connector = SellingPartnerApi::seller(
                clientId: (string) $config['client_id'],
                clientSecret: (string) $config['client_secret'],
                refreshToken: (string) $config['refresh_token'],
                endpoint: $regionService->spApiEndpointEnum($configuredRegion)
            );
            $api = $connector->financesV0();

            $nextToken = null;
            $feeLineRows = [];
            $seenShipmentFeeFingerprints = [];
            $events = 0;

            do {
                $response = $this->callWithRetries(
                    fn () => $nextToken
                        ? $api->listFinancialEvents(nextToken: $nextToken)
                        : $api->listFinancialEvents(
                            maxResultsPerPage: 100,
                            postedAfter: $from,
                            postedBefore: $to
                        ),
                    'finances.listFinancialEvents'
                );

                if (!$response || $response->status() >= 400) {
                    Log::warning('Fee sync: finances call failed', [
                        'region' => $configuredRegion,
                        'status' => $response?->status(),
                        'body' => $response?->body(),
                    ]);
                    break;
                }

                $payload = (array) ($response->json('payload') ?? []);
                $financialEvents = (array) ($payload['FinancialEvents'] ?? []);
                $events += $this->accumulateFeesFromFinancialEvents(
                    $financialEvents,
                    $feeLineRows,
                    $configuredRegion,
                    $seenShipmentFeeFingerprints
                );
                $nextToken = $payload['NextToken'] ?? null;
            } while (!empty($nextToken));

            $this->persistFeeLines($feeLineRows);
            $updated = $this->recalculateOrderFeesFromLines($configuredRegion);
            $summary['events'] += $events;
            $summary['orders_updated'] += $updated;
        }

        return $summary;
    }

    private function accumulateFeesFromFinancialEvents(
        array $financialEvents,
        array &$feeLineRows,
        string $region,
        array &$seenShipmentFeeFingerprints
    ): int
    {
        $events = 0;
        foreach ($financialEvents as $key => $eventList) {
            if (!is_string($key) || !str_ends_with($key, 'EventList') || !is_array($eventList)) {
                continue;
            }

            foreach ($eventList as $eventIndex => $event) {
                if (!is_array($event)) {
                    continue;
                }
                $events++;
                $orderId = trim((string) ($event['AmazonOrderId'] ?? ''));
                if ($orderId === '') {
                    continue;
                }
                $eventType = preg_replace('/List$/', '', $key) ?: $key;
                $postedDate = $this->normalizePostedDate($event['PostedDate'] ?? null);
                $isShipmentOrSettle = in_array($eventType, ['ShipmentEvent', 'ShipmentSettleEvent'], true);

                $fees = [];
                $this->extractFeeListAmounts($event, '', $fees);
                foreach ($fees as $feeIndex => $fee) {
                    $amount = (float) ($fee['amount'] ?? 0);
                    $currency = strtoupper(trim((string) ($fee['currency'] ?? '')));
                    if ($currency === '' || $amount == 0.0) {
                        continue;
                    }

                    if ($isShipmentOrSettle) {
                        $fingerprint = sha1(json_encode([
                            'order_id' => $orderId,
                            'posted_date' => $postedDate,
                            'fee_type' => (string) ($fee['fee_type'] ?? ''),
                            'amount' => number_format($amount, 2, '.', ''),
                            'currency' => $currency,
                            'raw_entry' => $fee['raw_entry'] ?? null,
                        ]));
                        if (isset($seenShipmentFeeFingerprints[$fingerprint])) {
                            continue;
                        }
                        $seenShipmentFeeFingerprints[$fingerprint] = $eventType;
                    }

                    $feeType = trim((string) ($fee['fee_type'] ?? ''));
                    $description = $feeType !== '' ? $feeType : (string) ($fee['path'] ?? 'Fee');
                    $rawEntryJson = json_encode($fee['raw_entry'] ?? null, JSON_UNESCAPED_SLASHES);
                    $hashBase = implode('|', [
                        $orderId,
                        $region,
                        (string) ($postedDate ?? ''),
                        $feeType,
                        number_format($amount, 2, '.', ''),
                        $currency,
                        (string) ($rawEntryJson ?? ''),
                    ]);

                    $feeLineRows[] = [
                        'fee_hash' => sha1($hashBase),
                        'amazon_order_id' => $orderId,
                        'region' => $region,
                        'event_type' => $eventType,
                        'fee_type' => $feeType !== '' ? $feeType : null,
                        'description' => $description,
                        'amount' => round($amount, 2),
                        'currency' => $currency,
                        'posted_date' => $postedDate,
                        'raw_line' => json_encode([
                            'event_type' => $eventType,
                            'path' => $fee['path'] ?? null,
                            'entry' => $fee['raw_entry'] ?? null,
                            'event' => $event,
                        ]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        return $events;
    }

    private function extractFeeListAmounts(array $node, string $path, array &$out): void
    {
        foreach ($node as $key => $value) {
            $childPath = $path === '' ? (string) $key : $path . '.' . (string) $key;

            if (is_array($value) && is_string($key) && str_ends_with($key, 'FeeList')) {
                foreach ($value as $entryIndex => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $feeAmount = $this->moneyAmount($entry['FeeAmount'] ?? null);
                    $feeCurrency = $this->moneyCurrency($entry['FeeAmount'] ?? null);
                    if ($feeAmount === null || $feeCurrency === null) {
                        continue;
                    }

                    $amount = $feeAmount;
                    $currency = $feeCurrency;

                    $taxAmount = $this->moneyAmount($entry['TaxAmount'] ?? null);
                    $taxCurrency = $this->moneyCurrency($entry['TaxAmount'] ?? null);
                    if ($taxAmount !== null && $taxCurrency !== null && strtoupper($taxCurrency) === strtoupper($feeCurrency)) {
                        $amount = (float) $feeAmount - (float) $taxAmount;
                    }

                    $out[] = [
                        'amount' => (float) $amount,
                        'currency' => (string) $currency,
                        'path' => $childPath . '[' . $entryIndex . ']',
                        'fee_type' => $entry['FeeType'] ?? null,
                        'raw_entry' => $entry,
                    ];
                }
                continue;
            }

            if (is_array($value)) {
                $this->extractFeeListAmounts($value, $childPath, $out);
            }
        }
    }

    private function recalculateOrderFeesFromLines(string $region): int
    {
        if (!DB::getSchemaBuilder()->hasTable('amazon_order_fee_lines')) {
            return 0;
        }

        $targetOrderIds = DB::table('orders')
            ->whereIn('marketplace_id', $this->marketplaceIdsForRegion($region))
            ->pluck('amazon_order_id')
            ->filter()
            ->values()
            ->all();

        if (empty($targetOrderIds)) {
            return 0;
        }

        $lines = DB::table('amazon_order_fee_lines')
            ->select('amazon_order_id', 'currency', DB::raw('SUM(amount) as fee_total'))
            ->where('region', $region)
            ->whereIn('amazon_order_id', $targetOrderIds)
            ->groupBy('amazon_order_id', 'currency')
            ->get();

        if ($lines->isEmpty()) {
            return 0;
        }

        $byOrder = [];
        foreach ($lines as $line) {
            $orderId = (string) ($line->amazon_order_id ?? '');
            $currency = strtoupper(trim((string) ($line->currency ?? '')));
            if ($orderId === '' || $currency === '') {
                continue;
            }
            if (!isset($byOrder[$orderId])) {
                $byOrder[$orderId] = [];
            }
            $byOrder[$orderId][$currency] = (float) ($line->fee_total ?? 0);
        }

        if (empty($byOrder)) {
            return 0;
        }

        $updated = 0;
        $orders = Order::query()
            ->whereIn('amazon_order_id', array_keys($byOrder))
            ->get(['id', 'amazon_order_id', 'order_total_currency']);

        foreach ($orders as $order) {
            $currencyMap = $byOrder[$order->amazon_order_id] ?? [];
            if (empty($currencyMap)) {
                continue;
            }
            $preferredCurrency = strtoupper(trim((string) ($order->order_total_currency ?? '')));
            if ($preferredCurrency !== '' && isset($currencyMap[$preferredCurrency])) {
                $currency = $preferredCurrency;
                $amount = $currencyMap[$preferredCurrency];
            } else {
                arsort($currencyMap, SORT_NUMERIC);
                $currency = (string) array_key_first($currencyMap);
                $amount = (float) ($currencyMap[$currency] ?? 0);
                if (count($currencyMap) > 1) {
                    Log::warning('Fee sync picked fallback currency for order', [
                        'order_id' => $order->amazon_order_id,
                        'region' => $region,
                        'currencies' => array_keys($currencyMap),
                        'picked' => $currency,
                    ]);
                }
            }

            $order->amazon_fee_total = round($amount, 2);
            $order->amazon_fee_currency = $currency;
            $order->amazon_fee_last_synced_at = now();
            $order->save();
            $updated++;
        }

        return $updated;
    }

    private function persistFeeLines(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('amazon_order_fee_lines')->upsert(
                $chunk,
                ['fee_hash'],
                ['region', 'event_type', 'fee_type', 'description', 'amount', 'currency', 'posted_date', 'raw_line', 'updated_at']
            );
        }
    }

    private function normalizePostedDate($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function callWithRetries(callable $callback, string $operation): ?Response
    {
        $lastResponse = null;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                /** @var Response $response */
                $response = $callback();
                $lastResponse = $response;
                if ($response->status() < 400) {
                    return $response;
                }
                if ($response->status() !== 429 && $response->status() < 500) {
                    return $response;
                }

                sleep($this->retryAfterSeconds($response));
            } catch (TooManyRequestsException $e) {
                $response = $e->getResponse();
                $lastResponse = $response;
                sleep($this->retryAfterSeconds($response));
            } catch (\Throwable $e) {
                Log::warning('Fee sync API exception', [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $lastResponse;
    }

    private function retryAfterSeconds(?Response $response): int
    {
        if (!$response) {
            return 10;
        }

        $retryAfter = $response->header('Retry-After');
        if (is_numeric($retryAfter)) {
            return max(1, (int) $retryAfter);
        }

        return 10;
    }

    private function moneyAmount($money): ?float
    {
        if (!is_array($money)) {
            return null;
        }

        $amount = $money['CurrencyAmount'] ?? $money['Amount'] ?? $money['amount'] ?? null;
        return is_numeric($amount) ? (float) $amount : null;
    }

    private function moneyCurrency($money): ?string
    {
        if (!is_array($money)) {
            return null;
        }

        $currency = trim((string) ($money['CurrencyCode'] ?? $money['currencyCode'] ?? ''));
        return $currency !== '' ? strtoupper($currency) : null;
    }

    private function marketplaceIdsForRegion(string $region): array
    {
        $region = strtoupper(trim($region));
        if ($region === 'NA') {
            $countries = ['US', 'CA', 'MX', 'BR'];
        } elseif ($region === 'FE') {
            $countries = ['JP', 'AU', 'SG', 'AE', 'IN', 'SA'];
        } else {
            $countries = ['GB', 'UK', 'AT', 'BE', 'DE', 'DK', 'ES', 'FI', 'FR', 'IE', 'IT', 'LU', 'NL', 'NO', 'PL', 'SE', 'CH', 'TR'];
        }

        return DB::table('marketplaces')
            ->whereIn(DB::raw("UPPER(COALESCE(country_code, ''))"), $countries)
            ->pluck('id')
            ->filter()
            ->values()
            ->all();
    }
}
