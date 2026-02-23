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
            $feeByOrderCurrency = [];
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
                    $feeByOrderCurrency,
                    $feeLineRows,
                    $configuredRegion,
                    $seenShipmentFeeFingerprints
                );
                $nextToken = $payload['NextToken'] ?? null;
            } while (!empty($nextToken));

            $this->persistFeeLines($feeLineRows);
            $updated = $this->persistOrderFees($feeByOrderCurrency, $configuredRegion);
            $summary['events'] += $events;
            $summary['orders_updated'] += $updated;
        }

        return $summary;
    }

    private function accumulateFeesFromFinancialEvents(
        array $financialEvents,
        array &$feeByOrderCurrency,
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

                    if (!isset($feeByOrderCurrency[$orderId])) {
                        $feeByOrderCurrency[$orderId] = [];
                    }
                    $feeByOrderCurrency[$orderId][$currency] = ($feeByOrderCurrency[$orderId][$currency] ?? 0.0) + $amount;

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
                    $amount = $entry['FeeAmount']['CurrencyAmount'] ?? $entry['FeeAmount']['Amount'] ?? null;
                    $currency = $entry['FeeAmount']['CurrencyCode'] ?? null;
                    if ($amount === null || $currency === null) {
                        continue;
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

    private function persistOrderFees(array $feeByOrderCurrency, string $region): int
    {
        if (empty($feeByOrderCurrency)) {
            return 0;
        }

        $updated = 0;
        $orderIds = array_keys($feeByOrderCurrency);
        $orders = Order::query()
            ->whereIn('amazon_order_id', $orderIds)
            ->get(['id', 'amazon_order_id', 'order_total_currency']);

        foreach ($orders as $order) {
            $currencyMap = $feeByOrderCurrency[$order->amazon_order_id] ?? [];
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
}
