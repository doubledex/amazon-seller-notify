<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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
                $events += $this->accumulateFeesFromFinancialEvents($financialEvents, $feeByOrderCurrency);
                $nextToken = $payload['NextToken'] ?? null;
            } while (!empty($nextToken));

            $updated = $this->persistOrderFees($feeByOrderCurrency, $configuredRegion);
            $summary['events'] += $events;
            $summary['orders_updated'] += $updated;
        }

        return $summary;
    }

    private function accumulateFeesFromFinancialEvents(array $financialEvents, array &$feeByOrderCurrency): int
    {
        $events = 0;
        foreach ($financialEvents as $key => $eventList) {
            if (!is_string($key) || !str_ends_with($key, 'EventList') || !is_array($eventList)) {
                continue;
            }

            foreach ($eventList as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $events++;
                $orderId = trim((string) ($event['AmazonOrderId'] ?? ''));
                if ($orderId === '') {
                    continue;
                }

                $fees = [];
                $this->extractFeeListAmounts($event, '', $fees);
                foreach ($fees as $fee) {
                    $amount = (float) ($fee['amount'] ?? 0);
                    $currency = strtoupper(trim((string) ($fee['currency'] ?? '')));
                    if ($currency === '' || $amount == 0.0) {
                        continue;
                    }
                    if (!isset($feeByOrderCurrency[$orderId])) {
                        $feeByOrderCurrency[$orderId] = [];
                    }
                    $feeByOrderCurrency[$orderId][$currency] = ($feeByOrderCurrency[$orderId][$currency] ?? 0.0) + $amount;
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
                foreach ($value as $entry) {
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
                        'path' => $childPath,
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
