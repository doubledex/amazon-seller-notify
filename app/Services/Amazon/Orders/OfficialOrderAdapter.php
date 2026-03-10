<?php

namespace App\Services\Amazon\Orders;

use App\Contracts\Amazon\AmazonOrderApi;
use App\Services\Amazon\OfficialSpApiService;
use App\Services\MarketplaceService;
use App\Services\RegionConfigService;
use SpApi\ApiException;

class OfficialOrderAdapter implements AmazonOrderApi
{
    private const MAX_ATTEMPTS = 4;

    public function __construct(
        private readonly OfficialSpApiService $officialSpApiService,
        private readonly MarketplaceService $marketplaceService,
        private readonly RegionConfigService $regionConfigService,
    ) {
    }

    public function getOrders(string $createdAfter, string $createdBefore, ?string $nextToken = null, ?string $region = null): array
    {
        $resolvedRegion = $this->resolveRegion($region);
        $ordersApi = $this->officialSpApiService->makeOrdersV0Api($resolvedRegion);
        if ($ordersApi === null) {
            return ['status' => 500, 'headers' => [], 'body' => [], 'error' => 'Unable to construct official Orders API client.'];
        }

        $marketplaceIds = $this->marketplaceService->getMarketplaceIdsForRegion($resolvedRegion);

        return $this->callWithRetries(function () use ($ordersApi, $nextToken, $marketplaceIds, $createdAfter, $createdBefore) {
            if ($nextToken !== null && trim($nextToken) !== '') {
                return $ordersApi->getOrdersWithHttpInfo(marketplace_ids: $marketplaceIds, next_token: $nextToken);
            }

            return $ordersApi->getOrdersWithHttpInfo(
                marketplace_ids: $marketplaceIds,
                created_after: $createdAfter,
                created_before: $createdBefore
            );
        });
    }

    public function getOrderItems(string $amazonOrderId, ?string $region = null): array
    {
        $resolvedRegion = $this->resolveRegion($region);
        $ordersApi = $this->officialSpApiService->makeOrdersV0Api($resolvedRegion);
        if ($ordersApi === null) {
            return ['status' => 500, 'headers' => [], 'body' => [], 'error' => 'Unable to construct official Orders API client.'];
        }

        return $this->callWithRetries(fn () => $ordersApi->getOrderItemsWithHttpInfo($amazonOrderId));
    }

    public function getOrderAddress(string $amazonOrderId, ?string $region = null): array
    {
        $resolvedRegion = $this->resolveRegion($region);
        $ordersApi = $this->officialSpApiService->makeOrdersV0Api($resolvedRegion);
        if ($ordersApi === null) {
            return ['status' => 500, 'headers' => [], 'body' => [], 'error' => 'Unable to construct official Orders API client.'];
        }

        return $this->callWithRetries(fn () => $ordersApi->getOrderAddressWithHttpInfo($amazonOrderId));
    }

    private function resolveRegion(?string $region): string
    {
        $resolved = strtoupper(trim((string) ($region ?: $this->regionConfigService->defaultSpApiRegion())));

        return in_array($resolved, ['EU', 'NA', 'FE'], true) ? $resolved : $this->regionConfigService->defaultSpApiRegion();
    }

    private function callWithRetries(callable $callback): array
    {
        $last = ['status' => 500, 'headers' => [], 'body' => [], 'error' => null];

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                [$model, $status, $headers] = $callback();
                $response = [
                    'status' => (int) $status,
                    'headers' => (array) $headers,
                    'body' => $this->modelToArray($model),
                ];
                $last = $response;

                if (($response['status'] < 400) || ($response['status'] !== 429 && $response['status'] < 500)) {
                    return $response;
                }

                usleep($this->retryDelayMicros($attempt, (array) $headers));
            } catch (ApiException $e) {
                $headers = (array) ($e->getResponseHeaders() ?? []);
                $status = (int) $e->getCode();
                $last = [
                    'status' => $status,
                    'headers' => $headers,
                    'body' => $this->modelToArray($e->getResponseBody()),
                    'error' => $e->getMessage(),
                ];

                if ($status !== 429 && $status < 500) {
                    return $last;
                }

                usleep($this->retryDelayMicros($attempt, $headers));
            } catch (\Throwable $e) {
                $last = [
                    'status' => 500,
                    'headers' => [],
                    'body' => [],
                    'error' => $e->getMessage(),
                ];
                break;
            }
        }

        return $last;
    }

    private function retryDelayMicros(int $attempt, array $headers): int
    {
        $retryAfter = $headers['Retry-After'][0] ?? $headers['retry-after'][0] ?? null;
        if (is_numeric($retryAfter)) {
            return max(100_000, (int) $retryAfter * 1_000_000);
        }

        $reset = $headers['x-amzn-RateLimit-Reset'][0]
            ?? $headers['x-amzn-ratelimit-reset'][0]
            ?? $headers['X-Amzn-RateLimit-Reset'][0]
            ?? null;
        if (is_numeric($reset)) {
            return max(100_000, (int) ceil((float) $reset * 1_000_000));
        }

        $baseMs = 200 * (2 ** max(0, $attempt));
        $jitterMs = random_int(0, 100);

        return (int) (($baseMs + $jitterMs) * 1000);
    }

    private function modelToArray(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $json = json_encode($value);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
