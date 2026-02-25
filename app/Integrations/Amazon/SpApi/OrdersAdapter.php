<?php

namespace App\Integrations\Amazon\SpApi;

use App\Services\MarketplaceService;
use Saloon\Http\Response;

class OrdersAdapter
{
    private mixed $connector;
    private mixed $ordersApi;
    private array $marketplaceIds;

    public function __construct(
        private readonly SpApiClientFactory $clientFactory,
        private readonly MarketplaceService $marketplaceService
    ) {
        $this->connector = $this->clientFactory->makeSellerConnector();
        $this->ordersApi = $this->connector->ordersV0();
        $this->marketplaceIds = $this->marketplaceService->getMarketplaceIds($this->connector);
    }

    public function hasMarketplaceIds(): bool
    {
        return !empty($this->marketplaceIds);
    }

    public function getOrders(string $createdAfter, string $createdBefore, ?string $nextToken = null): Response
    {
        if ($nextToken) {
            return $this->ordersApi->getOrders(
                marketplaceIds: $this->marketplaceIds,
                nextToken: $nextToken
            );
        }

        return $this->ordersApi->getOrders(
            createdAfter: $createdAfter,
            createdBefore: $createdBefore,
            marketplaceIds: $this->marketplaceIds
        );
    }

    public function getOrderItems(string $orderId): Response
    {
        return $this->ordersApi->getOrderItems($orderId);
    }

    public function getOrderAddress(string $orderId): Response
    {
        return $this->ordersApi->getOrderAddress($orderId);
    }
}
