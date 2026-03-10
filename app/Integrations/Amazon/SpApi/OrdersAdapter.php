<?php

namespace App\Integrations\Amazon\SpApi;

use App\Services\Amazon\OfficialSpApiService;
use App\Services\MarketplaceService;
use App\Services\RegionConfigService;
use SpApi\ApiException;

class OrdersAdapter
{
    private mixed $ordersApi;
    private array $marketplaceIds;

    public function __construct(
        private readonly OfficialSpApiService $officialSpApiService,
        private readonly MarketplaceService $marketplaceService,
        private readonly RegionConfigService $regionConfigService,
        private readonly ?string $region = null
    ) {
        $resolvedRegion = strtoupper(trim((string) ($this->region ?: $this->regionConfigService->defaultSpApiRegion())));
        $this->ordersApi = $this->officialSpApiService->makeOrdersV0Api($resolvedRegion);
        $this->marketplaceIds = $this->marketplaceService->getMarketplaceIds();
    }

    public function hasMarketplaceIds(): bool
    {
        return !empty($this->marketplaceIds);
    }

    public function getOrders(string $createdAfter, string $createdBefore, ?string $nextToken = null): array
    {
        if ($this->ordersApi === null) {
            return ['status' => 500, 'headers' => [], 'body' => [], 'error' => 'Unable to construct official Orders API client.'];
        }

        try {
        if ($nextToken) {
                [$model, $status, $headers] = $this->ordersApi->getOrdersWithHttpInfo(
                    marketplace_ids: $this->marketplaceIds,
                    next_token: $nextToken
                );
            } else {
                [$model, $status, $headers] = $this->ordersApi->getOrdersWithHttpInfo(
                    created_after: $createdAfter,
                    created_before: $createdBefore,
                    marketplace_ids: $this->marketplaceIds
                );
            }

            return ['status' => (int) $status, 'headers' => (array) $headers, 'body' => $this->modelToArray($model)];
        } catch (ApiException $e) {
            return [
                'status' => (int) $e->getCode(),
                'headers' => (array) ($e->getResponseHeaders() ?? []),
                'body' => $this->modelToArray($e->getResponseBody()),
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getOrderItems(string $orderId): array
    {
        if ($this->ordersApi === null) {
            return ['status' => 500, 'headers' => [], 'body' => [], 'error' => 'Unable to construct official Orders API client.'];
        }

        try {
            [$model, $status, $headers] = $this->ordersApi->getOrderItemsWithHttpInfo($orderId);
            return ['status' => (int) $status, 'headers' => (array) $headers, 'body' => $this->modelToArray($model)];
        } catch (ApiException $e) {
            return [
                'status' => (int) $e->getCode(),
                'headers' => (array) ($e->getResponseHeaders() ?? []),
                'body' => $this->modelToArray($e->getResponseBody()),
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getOrderAddress(string $orderId): array
    {
        if ($this->ordersApi === null) {
            return ['status' => 500, 'headers' => [], 'body' => [], 'error' => 'Unable to construct official Orders API client.'];
        }

        try {
            [$model, $status, $headers] = $this->ordersApi->getOrderAddressWithHttpInfo($orderId);
            return ['status' => (int) $status, 'headers' => (array) $headers, 'body' => $this->modelToArray($model)];
        } catch (ApiException $e) {
            return [
                'status' => (int) $e->getCode(),
                'headers' => (array) ($e->getResponseHeaders() ?? []),
                'body' => $this->modelToArray($e->getResponseBody()),
                'error' => $e->getMessage(),
            ];
        }
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
