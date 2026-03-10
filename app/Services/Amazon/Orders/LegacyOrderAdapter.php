<?php

namespace App\Services\Amazon\Orders;

use App\Contracts\Amazon\AmazonOrderApi;
use App\Integrations\Amazon\SpApi\LegacySpApiClientFactory;
use App\Services\Amazon\Support\AmazonRequestPolicy;
use App\Services\MarketplaceService;
use Saloon\Http\Response;

class LegacyOrderAdapter implements AmazonOrderApi
{
    public function __construct(
        private readonly LegacySpApiClientFactory $clientFactory,
        private readonly MarketplaceService $marketplaceService,
        private readonly AmazonRequestPolicy $requestPolicy,
    ) {
    }

    public function getOrders(string $createdAfter, string $createdBefore, ?string $nextToken = null, ?string $region = null): Response
    {
        $connector = $this->clientFactory->makeSellerConnector($region);
        $ordersApi = $connector->ordersV0();
        $marketplaceIds = $this->marketplaceService->getMarketplaceIds($connector);

        return $this->requestPolicy->execute('orders.getOrders', function () use ($nextToken, $ordersApi, $marketplaceIds, $createdAfter, $createdBefore) {
            if ($nextToken) {
                return $ordersApi->getOrders(marketplaceIds: $marketplaceIds, nextToken: $nextToken);
            }

            return $ordersApi->getOrders(
                createdAfter: $createdAfter,
                createdBefore: $createdBefore,
                marketplaceIds: $marketplaceIds
            );
        });
    }

    public function getOrderItems(string $amazonOrderId, ?string $region = null): Response
    {
        $connector = $this->clientFactory->makeSellerConnector($region);

        return $this->requestPolicy->execute(
            'orders.getOrderItems',
            fn () => $connector->ordersV0()->getOrderItems($amazonOrderId)
        );
    }

    public function getOrderAddress(string $amazonOrderId, ?string $region = null): Response
    {
        $connector = $this->clientFactory->makeSellerConnector($region);

        return $this->requestPolicy->execute(
            'orders.getOrderAddress',
            fn () => $connector->ordersV0()->getOrderAddress($amazonOrderId)
        );
    }
}
