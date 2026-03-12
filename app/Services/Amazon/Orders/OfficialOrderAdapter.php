<?php

namespace App\Services\Amazon\Orders;

use App\Contracts\Amazon\AmazonOrderApi;
use App\Services\Amazon\OfficialSpApiService;
use App\Services\MarketplaceService;
use App\Services\RegionConfigService;
use DateTime;
use SpApi\ApiException;

class OfficialOrderAdapter implements AmazonOrderApi
{
    private const MAX_ATTEMPTS = 4;
    private const SEARCH_INCLUDED_DATA = ['BUYER', 'RECIPIENT', 'PROCEEDS', 'FULFILLMENT', 'PACKAGES'];
    private const GET_ORDER_INCLUDED_DATA = ['BUYER', 'RECIPIENT', 'PROCEEDS', 'FULFILLMENT', 'PACKAGES'];

    public function __construct(
        private readonly OfficialSpApiService $officialSpApiService,
        private readonly MarketplaceService $marketplaceService,
        private readonly RegionConfigService $regionConfigService,
    ) {
    }

    public function getOrders(string $createdAfter, string $createdBefore, ?string $nextToken = null, ?string $region = null): array
    {
        $resolvedRegion = $this->resolveRegion($region);
        $searchOrdersApi = $this->officialSpApiService->makeSearchOrdersV20260101Api($resolvedRegion);
        if ($searchOrdersApi === null) {
            return ['status' => 500, 'headers' => [], 'body' => [], 'error' => 'Unable to construct official Orders API client.'];
        }

        $marketplaceIds = $this->marketplaceService->getMarketplaceIdsForRegion($resolvedRegion);

        return $this->callWithRetries(function () use ($searchOrdersApi, $nextToken, $marketplaceIds, $createdAfter, $createdBefore) {
            $paginationToken = trim((string) $nextToken);
            return $searchOrdersApi->searchOrdersWithHttpInfo(
                marketplace_ids: $marketplaceIds,
                created_after: new DateTime($createdAfter),
                created_before: new DateTime($createdBefore),
                pagination_token: $paginationToken !== '' ? $paginationToken : null,
                included_data: self::SEARCH_INCLUDED_DATA
            );
        }, fn (array $body) => $this->normalizeSearchOrdersBody($body));
    }

    public function getOrderItems(string $amazonOrderId, ?string $region = null): array
    {
        $resolvedRegion = $this->resolveRegion($region);
        $getOrderApi = $this->officialSpApiService->makeGetOrderV20260101Api($resolvedRegion);
        if ($getOrderApi === null) {
            return ['status' => 500, 'headers' => [], 'body' => [], 'error' => 'Unable to construct official Orders API client.'];
        }

        return $this->callWithRetries(
            fn () => $getOrderApi->getOrderWithHttpInfo($amazonOrderId, self::GET_ORDER_INCLUDED_DATA),
            fn (array $body) => $this->normalizeGetOrderItemsBody($body, $amazonOrderId)
        );
    }

    public function getOrderAddress(string $amazonOrderId, ?string $region = null): array
    {
        $resolvedRegion = $this->resolveRegion($region);
        $getOrderApi = $this->officialSpApiService->makeGetOrderV20260101Api($resolvedRegion);
        if ($getOrderApi === null) {
            return ['status' => 500, 'headers' => [], 'body' => [], 'error' => 'Unable to construct official Orders API client.'];
        }

        return $this->callWithRetries(
            fn () => $getOrderApi->getOrderWithHttpInfo($amazonOrderId, ['RECIPIENT']),
            fn (array $body) => $this->normalizeGetOrderAddressBody($body, $amazonOrderId)
        );
    }

    private function resolveRegion(?string $region): string
    {
        $resolved = strtoupper(trim((string) ($region ?: $this->regionConfigService->defaultSpApiRegion())));

        return in_array($resolved, ['EU', 'NA', 'FE'], true) ? $resolved : $this->regionConfigService->defaultSpApiRegion();
    }

    private function callWithRetries(callable $callback, ?callable $normalizeBody = null): array
    {
        $last = ['status' => 500, 'headers' => [], 'body' => [], 'error' => null];

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                [$model, $status, $headers] = $callback();
                $body = $this->modelToArray($model);
                if ($normalizeBody !== null) {
                    $body = $normalizeBody($body);
                }
                $response = [
                    'status' => (int) $status,
                    'headers' => (array) $headers,
                    'body' => $body,
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

    private function normalizeSearchOrdersBody(array $body): array
    {
        $orders = (array) ($body['orders'] ?? []);
        $pagination = (array) ($body['pagination'] ?? []);
        $normalizedOrders = array_map(fn ($order) => $this->normalizeOrderSummary((array) $order), $orders);

        return [
            'payload' => [
                'Orders' => array_values($normalizedOrders),
                'NextToken' => (string) ($pagination['nextToken'] ?? ''),
            ],
        ];
    }

    private function normalizeGetOrderItemsBody(array $body, string $amazonOrderId): array
    {
        $order = (array) ($body['order'] ?? []);
        $items = (array) ($order['orderItems'] ?? []);
        $summary = $this->normalizeOrderSummary($order);

        return [
            'payload' => [
                'AmazonOrderId' => (string) ($order['orderId'] ?? $amazonOrderId),
                'OrderItems' => array_values(array_map(fn ($item) => $this->normalizeOrderItem((array) $item), $items)),
                'OrderSummary' => $summary,
            ],
        ];
    }

    private function normalizeGetOrderAddressBody(array $body, string $amazonOrderId): array
    {
        $order = (array) ($body['order'] ?? []);
        $recipient = (array) ($order['recipient'] ?? []);
        $address = (array) ($recipient['deliveryAddress'] ?? []);
        $summary = $this->normalizeOrderSummary($order);

        return [
            'payload' => [
                'AmazonOrderId' => (string) ($order['orderId'] ?? $amazonOrderId),
                'ShippingAddress' => [
                    'Name' => $address['name'] ?? null,
                    'AddressLine1' => $address['addressLine1'] ?? null,
                    'AddressLine2' => $address['addressLine2'] ?? null,
                    'AddressLine3' => $address['addressLine3'] ?? null,
                    'City' => $address['city'] ?? null,
                    'County' => $address['districtOrCounty'] ?? null,
                    'District' => $address['districtOrCounty'] ?? null,
                    'StateOrRegion' => $address['stateOrRegion'] ?? null,
                    'Municipality' => $address['municipality'] ?? null,
                    'PostalCode' => $address['postalCode'] ?? null,
                    'CountryCode' => $address['countryCode'] ?? null,
                    'Phone' => $address['phone'] ?? null,
                    'AddressType' => $address['addressType'] ?? null,
                ],
                'OrderSummary' => $summary,
            ],
        ];
    }

    private function normalizeOrderSummary(array $order): array
    {
        $recipient = (array) ($order['recipient'] ?? []);
        $address = (array) ($recipient['deliveryAddress'] ?? []);
        $salesChannel = (array) ($order['salesChannel'] ?? []);
        $fulfillment = (array) ($order['fulfillment'] ?? []);
        $proceeds = (array) ($order['proceeds'] ?? []);
        $grandTotal = (array) ($proceeds['grandTotal'] ?? []);
        $buyer = (array) ($order['buyer'] ?? []);
        $itemCounts = (array) ($order['itemCounts'] ?? []);
        $payment = (array) ($order['payment'] ?? []);

        $orderStatus = $fulfillment['fulfillmentStatus']
            ?? $order['orderStatus']
            ?? $order['status']
            ?? null;

        $fulfilledBy = strtoupper(trim((string) (
            $fulfillment['fulfilledBy']
            ?? $order['fulfillmentChannel']
            ?? $order['fulfilledBy']
            ?? ''
        )));
        $fulfillmentChannel = match ($fulfilledBy) {
            'AMAZON', 'AFN', 'FBA' => 'AFN',
            'SELLER', 'MFN', 'FBM', 'MERCHANT' => 'MFN',
            default => ($fulfilledBy !== '' ? $fulfilledBy : null),
        };

        $proceedsPaymentMethods = (array) ($proceeds['paymentMethods'] ?? []);
        $proceedsFirstPaymentMethod = $proceedsPaymentMethods[0] ?? null;
        if (is_array($proceedsFirstPaymentMethod)) {
            $proceedsFirstPaymentMethod = $proceedsFirstPaymentMethod['paymentMethod']
                ?? $proceedsFirstPaymentMethod['method']
                ?? null;
        }
        $paymentMethod = $order['paymentMethod']
            ?? $payment['paymentMethod']
            ?? $payment['method']
            ?? $proceeds['paymentMethod']
            ?? $proceeds['method']
            ?? $proceedsFirstPaymentMethod
            ?? null;

        $itemsShipped = $itemCounts['numberOfItemsShipped']
            ?? $itemCounts['itemsShipped']
            ?? $itemCounts['quantityShipped']
            ?? $itemCounts['shippedCount']
            ?? null;
        $itemsUnshipped = $itemCounts['numberOfItemsUnshipped']
            ?? $itemCounts['itemsUnshipped']
            ?? $itemCounts['quantityUnshipped']
            ?? $itemCounts['unshippedCount']
            ?? null;

        return [
            'AmazonOrderId' => $order['orderId'] ?? null,
            'PurchaseDate' => $order['createdTime'] ?? null,
            'LastUpdateDate' => $order['lastUpdatedTime'] ?? null,
            'OrderStatus' => $orderStatus,
            'FulfillmentChannel' => $fulfillmentChannel,
            'SalesChannel' => $salesChannel['channelName'] ?? null,
            'MarketplaceId' => $salesChannel['marketplaceId'] ?? null,
            'IsBusinessOrder' => !empty($buyer['buyerCompanyName']),
            'PaymentMethodDetails' => $paymentMethod ? [$paymentMethod] : [],
            'PaymentMethod' => $paymentMethod,
            'NumberOfItemsShipped' => is_numeric($itemsShipped) ? (int) $itemsShipped : null,
            'NumberOfItemsUnshipped' => is_numeric($itemsUnshipped) ? (int) $itemsUnshipped : null,
            'OrderTotal' => [
                'Amount' => $grandTotal['amount'] ?? null,
                'CurrencyCode' => $grandTotal['currencyCode'] ?? null,
            ],
            'ShippingAddress' => [
                'City' => $address['city'] ?? null,
                'CountryCode' => $address['countryCode'] ?? null,
                'PostalCode' => $address['postalCode'] ?? null,
                'CompanyName' => $address['companyName'] ?? ($buyer['buyerCompanyName'] ?? null),
                'StateOrRegion' => $address['stateOrRegion'] ?? null,
            ],
        ];
    }

    private function normalizeOrderItem(array $item): array
    {
        $product = (array) ($item['product'] ?? []);
        $price = (array) ($product['price'] ?? []);
        $unitPrice = (array) ($price['unitPrice'] ?? []);
        $fulfillment = (array) ($item['fulfillment'] ?? []);
        $proceeds = (array) ($item['proceeds'] ?? []);
        $breakdowns = array_values(array_filter(array_map(function ($breakdown) {
            if (!is_array($breakdown)) {
                return null;
            }
            $subtotal = (array) ($breakdown['subtotal'] ?? []);
            return [
                'Type' => $breakdown['type'] ?? null,
                'Subtotal' => [
                    'Amount' => $subtotal['amount'] ?? null,
                    'CurrencyCode' => $subtotal['currencyCode'] ?? null,
                ],
            ];
        }, (array) ($proceeds['breakdowns'] ?? []))));

        return [
            'OrderItemId' => $item['orderItemId'] ?? null,
            'ASIN' => $product['asin'] ?? null,
            'SellerSKU' => $product['sellerSku'] ?? null,
            'Title' => $product['title'] ?? null,
            'QuantityOrdered' => $item['quantityOrdered'] ?? null,
            'QuantityShipped' => $fulfillment['quantityFulfilled'] ?? null,
            'QuantityUnshipped' => $fulfillment['quantityUnfulfilled'] ?? null,
            'ItemPrice' => [
                'Amount' => $unitPrice['amount'] ?? ($price['amount'] ?? null),
                'CurrencyCode' => $unitPrice['currencyCode'] ?? ($price['currencyCode'] ?? null),
            ],
            'Proceeds' => [
                'Breakdowns' => $breakdowns,
            ],
        ];
    }
}
