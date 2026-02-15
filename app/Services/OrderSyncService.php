<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShipAddress;
use App\Models\PostalCodeGeo;
use Illuminate\Support\Facades\Log;
use SellingPartnerApi\Enums\Endpoint;
use SellingPartnerApi\SellingPartnerApi;
use Saloon\Exceptions\Request\Statuses\TooManyRequestsException;
use Saloon\Http\Response;

class OrderSyncService
{
    private const FINAL_STATUSES = ['Shipped', 'Canceled', 'Unfulfillable'];
    private const GEO_MAX_PER_RUN = 50;
    private const MAX_API_RETRY_ATTEMPTS = 6;

    public function sync(
        int $days,
        ?string $endBefore,
        int $maxPages,
        int $itemsLimit,
        int $addressLimit,
        ?string $region = null
    ): array {
        $days = max(1, min($days, 30));
        $maxPages = max(1, min($maxPages, 20));
        $itemsLimit = max(0, min($itemsLimit, 500));
        $addressLimit = max(0, min($addressLimit, 500));

        $regionConfig = (new RegionConfigService())->spApiConfig($region);
        $endpoint = Endpoint::tryFrom((string) $regionConfig['endpoint']) ?? Endpoint::EU;

        $connector = SellingPartnerApi::seller(
            clientId: (string) $regionConfig['client_id'],
            clientSecret: (string) $regionConfig['client_secret'],
            refreshToken: (string) $regionConfig['refresh_token'],
            endpoint: $endpoint
        );

        $ordersApi = $connector->ordersV0();
        $marketplaceService = new MarketplaceService();
        $marketplaceIds = $marketplaceService->getMarketplaceIds($connector);
        if (empty($marketplaceIds)) {
            return [
                'ok' => false,
                'message' => 'No marketplace IDs configured.',
            ];
        }

        $createdBefore = $this->normalizeEndBefore($endBefore);
        $createdAfter = (new \DateTime($createdBefore))->modify("-{$days} days")->format('Y-m-d\TH:i:s\Z');

        $nextToken = null;
        $page = 0;
        $totalOrders = 0;
        $itemsFetched = 0;
        $addressesFetched = 0;
        $geocoded = 0;

        do {
            $page++;
            $response = $this->callSpApiWithRetries(
                fn () => $nextToken
                    ? $ordersApi->getOrders(marketplaceIds: $marketplaceIds, nextToken: $nextToken)
                    : $ordersApi->getOrders(createdAfter: $createdAfter, createdBefore: $createdBefore, marketplaceIds: $marketplaceIds),
                'orders.getOrders',
                self::MAX_API_RETRY_ATTEMPTS
            );

            if (!$response) {
                return [
                    'ok' => false,
                    'message' => 'Orders API error after retries.',
                ];
            }

            if ($response->status() >= 400) {
                Log::error('Orders sync error', [
                    'status' => $response->status(),
                    'request_id' => $this->extractRequestId($response),
                    'body' => $response->body(),
                ]);
                return [
                    'ok' => false,
                    'message' => 'Orders API error: ' . $response->status(),
                ];
            }

            $data = $response->json();
            $payload = $data['payload'] ?? [];
            $orders = $payload['Orders'] ?? [];
            $nextToken = $payload['NextToken'] ?? null;

            if (!empty($orders)) {
                $rows = [];
                foreach ($orders as $order) {
                    $ship = $order['ShippingAddress'] ?? [];
                    $paymentMethod = $order['PaymentMethodDetails'][0] ?? ($order['PaymentMethod'] ?? null);
                    $purchaseDate = $order['PurchaseDate'] ?? null;
                    if ($purchaseDate) {
                        try {
                            $purchaseDate = (new \DateTime($purchaseDate))->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            $purchaseDate = null;
                        }
                    }
                    $rows[] = [
                        'amazon_order_id' => $order['AmazonOrderId'] ?? null,
                        'purchase_date' => $purchaseDate,
                        'order_status' => $order['OrderStatus'] ?? null,
                        'fulfillment_channel' => $order['FulfillmentChannel'] ?? null,
                        'payment_method' => $paymentMethod,
                        'sales_channel' => $order['SalesChannel'] ?? null,
                        'marketplace_id' => $order['MarketplaceId'] ?? null,
                        'is_business_order' => !empty($order['IsBusinessOrder']),
                        'order_total_amount' => $order['OrderTotal']['Amount'] ?? null,
                        'order_total_currency' => $order['OrderTotal']['CurrencyCode'] ?? null,
                        'shipping_city' => $ship['City'] ?? null,
                        'shipping_country_code' => $ship['CountryCode'] ?? null,
                        'shipping_postal_code' => $ship['PostalCode'] ?? null,
                        'shipping_company' => $ship['CompanyName'] ?? null,
                        'shipping_region' => $ship['StateOrRegion'] ?? null,
                        'raw_order' => json_encode($order),
                        'last_synced_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                $rows = array_values(array_filter($rows, fn ($r) => !empty($r['amazon_order_id'])));
                if (!empty($rows)) {
                    Order::upsert(
                        $rows,
                        ['amazon_order_id'],
                        [
                            'purchase_date',
                            'order_status',
                            'fulfillment_channel',
                            'payment_method',
                            'sales_channel',
                            'marketplace_id',
                            'is_business_order',
                            'order_total_amount',
                            'order_total_currency',
                            'shipping_city',
                            'shipping_country_code',
                            'shipping_postal_code',
                            'shipping_company',
                            'shipping_region',
                            'raw_order',
                            'last_synced_at',
                            'updated_at',
                        ]
                    );
                }

                $totalOrders += count($rows);

                foreach ($orders as $order) {
                    $orderId = $order['AmazonOrderId'] ?? null;
                    if (!$orderId) {
                        continue;
                    }

                    $status = $order['OrderStatus'] ?? null;
                    $needsDetails = !$status || !in_array($status, self::FINAL_STATUSES, true);
                    $itemsMissing = OrderItem::query()->where('amazon_order_id', $orderId)->count() === 0;
                    $addressMissing = OrderShipAddress::query()->where('order_id', $orderId)->count() === 0;

                    if (($needsDetails || $itemsMissing) && $itemsFetched < $itemsLimit) {
                        $itemsResponse = $this->callSpApiWithRetries(
                            fn () => $ordersApi->getOrderItems($orderId),
                            'orders.getOrderItems',
                            self::MAX_API_RETRY_ATTEMPTS
                        );

                        if ($itemsResponse && $itemsResponse->status() < 400) {
                            $itemsData = $itemsResponse->json();
                            $items = $itemsData['payload']['OrderItems'] ?? [];
                            $isMarketplaceFacilitator = false;
                            foreach ($items as $item) {
                                $itemId = $item['OrderItemId'] ?? null;
                                if (!$itemId) {
                                    continue;
                                }
                                OrderItem::updateOrCreate(
                                    ['order_item_id' => $itemId],
                                    [
                                        'amazon_order_id' => $orderId,
                                        'asin' => $item['ASIN'] ?? null,
                                        'seller_sku' => $item['SellerSKU'] ?? null,
                                        'title' => $item['Title'] ?? null,
                                        'quantity_ordered' => $item['QuantityOrdered'] ?? null,
                                        'item_price_amount' => $item['ItemPrice']['Amount'] ?? null,
                                        'item_price_currency' => $item['ItemPrice']['CurrencyCode'] ?? null,
                                        'raw_item' => json_encode($item),
                                    ]
                                );

                                if (!$isMarketplaceFacilitator && $this->isMarketplaceFacilitatorItem($item)) {
                                    $isMarketplaceFacilitator = true;
                                }
                            }

                            Order::query()
                                ->where('amazon_order_id', $orderId)
                                ->update(['is_marketplace_facilitator' => $isMarketplaceFacilitator]);
                            $itemsFetched++;
                        }
                    }

                    if (($needsDetails || $addressMissing) && $addressesFetched < $addressLimit) {
                        $addrResponse = $this->callSpApiWithRetries(
                            fn () => $ordersApi->getOrderAddress($orderId),
                            'orders.getOrderAddress',
                            self::MAX_API_RETRY_ATTEMPTS
                        );

                        if ($addrResponse && $addrResponse->status() < 400) {
                            $addrData = $addrResponse->json();
                            $addr = $addrData['payload']['ShippingAddress'] ?? [];
                            OrderShipAddress::updateOrCreate(
                                ['order_id' => $orderId],
                                [
                                    'country_code' => $addr['CountryCode'] ?? null,
                                    'postal_code' => $addr['PostalCode'] ?? null,
                                    'city' => $addr['City'] ?? null,
                                    'region' => $addr['StateOrRegion'] ?? null,
                                    'raw_address' => $addr,
                                ]
                            );
                            $addressesFetched++;
                        }
                    }

                    if ($geocoded < self::GEO_MAX_PER_RUN) {
                        $ship = $order['ShippingAddress'] ?? [];
                        $country = $ship['CountryCode'] ?? null;
                        $postal = $ship['PostalCode'] ?? null;

                        if ((!$country || !$postal) && $orderId) {
                            $cached = OrderShipAddress::query()->where('order_id', $orderId)->first();
                            $country = $country ?: ($cached->country_code ?? null);
                            $postal = $postal ?: ($cached->postal_code ?? null);
                        }

                        if ($country && $postal) {
                            $exists = PostalCodeGeo::query()
                                ->where('country_code', strtoupper($country))
                                ->where('postal_code', strtoupper($postal))
                                ->exists();
                            if (!$exists) {
                                $geocoder = new PostalGeocoder();
                                $result = $geocoder->geocode($country, $postal);
                                if ($result) {
                                    PostalCodeGeo::updateOrCreate(
                                        ['country_code' => strtoupper($country), 'postal_code' => strtoupper($postal)],
                                        ['lat' => $result['lat'], 'lng' => $result['lng'], 'source' => $result['source'] ?? null]
                                    );
                                    $geocoded++;
                                }
                            }
                        }
                    }
                }
            }

        } while ($nextToken && $page < $maxPages);

        return [
            'ok' => true,
            'message' => "Synced orders: {$totalOrders}, items fetched: {$itemsFetched}, addresses fetched: {$addressesFetched}, geocoded: {$geocoded}",
        ];
    }

    private function normalizeEndBefore($value): string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return now()->subMinutes(2)->format('Y-m-d\TH:i:s\Z');
        }

        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $trimmed)) {
            return $trimmed . 'T00:00:00Z';
        }

        try {
            return (new \DateTime($trimmed))->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception $e) {
            return now()->subMinutes(2)->format('Y-m-d\TH:i:s\Z');
        }
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

        $limitReset = $response->header('x-amzn-RateLimit-Reset') ?? $response->header('X-Amzn-RateLimit-Reset');
        if (is_numeric($limitReset)) {
            return max(1, (int) ceil($limitReset));
        }

        return 10;
    }

    private function callSpApiWithRetries(callable $callback, string $operation, int $maxAttempts): ?Response
    {
        $lastResponse = null;

        for ($attempt = 0; $attempt < max(1, $maxAttempts); $attempt++) {
            try {
                /** @var Response $response */
                $response = $callback();
                $lastResponse = $response;

                if ($response->status() < 400) {
                    return $response;
                }

                if ($response->status() !== 429 && $response->status() < 500) {
                    Log::warning('SP-API non-retryable status', [
                        'operation' => $operation,
                        'attempt' => $attempt,
                        'status' => $response->status(),
                        'request_id' => $this->extractRequestId($response),
                    ]);
                    return $response;
                }

                $sleep = $this->withJitter($this->retryAfterSeconds($response), 0.25);
                Log::warning('SP-API retrying response status', [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'status' => $response->status(),
                    'request_id' => $this->extractRequestId($response),
                    'sleep_seconds' => $sleep,
                ]);
                sleep($sleep);
            } catch (TooManyRequestsException $e) {
                $response = $e->getResponse();
                $lastResponse = $response;
                $sleep = $this->withJitter($this->retryAfterSeconds($response), 0.25);
                Log::warning('SP-API throttled exception', [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'status' => $response?->status(),
                    'request_id' => $this->extractRequestId($response),
                    'sleep_seconds' => $sleep,
                ]);
                sleep($sleep);
            } catch (\Throwable $e) {
                if (method_exists($e, 'getResponse')) {
                    $response = $e->getResponse();
                    if ($response instanceof Response) {
                        $lastResponse = $response;
                        if ($response->status() === 429 || $response->status() >= 500) {
                            $sleep = $this->withJitter($this->retryAfterSeconds($response), 0.25);
                            Log::warning('SP-API exception retry', [
                                'operation' => $operation,
                                'attempt' => $attempt,
                                'status' => $response->status(),
                                'request_id' => $this->extractRequestId($response),
                                'sleep_seconds' => $sleep,
                                'error' => $e->getMessage(),
                            ]);
                            sleep($sleep);
                            continue;
                        }
                    }
                }

                Log::error('SP-API exception without retry', [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }

        return $lastResponse;
    }

    private function withJitter(int $seconds, float $ratio = 0.25): int
    {
        $seconds = max(1, $seconds);
        $jitter = (int) ceil($seconds * max(0.0, $ratio));
        if ($jitter <= 0) {
            return $seconds;
        }

        try {
            $offset = random_int(-$jitter, $jitter);
        } catch (\Throwable) {
            $offset = 0;
        }

        return max(1, $seconds + $offset);
    }

    private function extractRequestId(?Response $response): ?string
    {
        if (!$response) {
            return null;
        }

        $requestId = (string) ($response->header('x-amzn-RequestId')
            ?? $response->header('x-amzn-requestid')
            ?? $response->header('x-amz-request-id')
            ?? '');
        $requestId = trim($requestId);
        return $requestId !== '' ? $requestId : null;
    }

    private function isMarketplaceFacilitatorItem(array $item): bool
    {
        $taxCollection = $item['TaxCollection'] ?? null;
        if (!is_array($taxCollection)) {
            return false;
        }

        $model = strtolower(trim((string) ($taxCollection['Model'] ?? '')));
        $responsibleParty = strtolower(trim((string) ($taxCollection['ResponsibleParty'] ?? '')));

        if ($model === '' && $responsibleParty === '') {
            return false;
        }

        return str_contains($model, 'marketplace')
            || str_contains($responsibleParty, 'marketplace')
            || str_contains($responsibleParty, 'amazon');
    }
}
