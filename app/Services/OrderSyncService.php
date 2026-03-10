<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSyncRun;
use App\Models\OrderShipAddress;
use App\Models\PostalCodeGeo;
use App\Services\Amazon\Orders\OfficialOrderAdapter;
use App\Services\Amazon\OfficialSpApiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class OrderSyncService
{
    private const FINAL_STATUSES = ['Shipped', 'Canceled', 'Unfulfillable'];
    private const GEO_MAX_PER_RUN = 50;

    public function __construct(private readonly ?OfficialSpApiService $officialSpApiService = null)
    {
    }

    public function sync(
        int $days,
        ?string $endBefore,
        int $maxPages,
        int $itemsLimit,
        int $addressLimit,
        ?string $region = null,
        ?string $source = null
    ): array {
        $days = max(1, min($days, 30));
        $maxPages = max(1, min($maxPages, 20));
        $itemsLimit = max(0, min($itemsLimit, 500));
        $addressLimit = max(0, min($addressLimit, 500));

        $region = $region ? strtoupper(trim($region)) : null;
        if ($region !== null && !in_array($region, ['EU', 'NA', 'FE'], true)) {
            return [
                'ok' => false,
                'message' => "Invalid region '{$region}'. Allowed: EU, NA, FE.",
            ];
        }

        if ($region !== null) {
            return $this->syncSingleRegion($days, $endBefore, $maxPages, $itemsLimit, $addressLimit, $region, $source);
        }

        $regionService = new RegionConfigService();
        $regions = $regionService->spApiRegions();
        if (empty($regions)) {
            return [
                'ok' => false,
                'message' => 'No SP-API regions configured.',
            ];
        }

        $failed = [];
        $messages = [];
        foreach ($regions as $regionCode) {
            try {
                $result = $this->syncSingleRegion($days, $endBefore, $maxPages, $itemsLimit, $addressLimit, $regionCode, $source);
            } catch (\Throwable $e) {
                Log::error('orders:sync region exception', [
                    'region' => $regionCode,
                    'error' => $e->getMessage(),
                ]);
                $result = [
                    'ok' => false,
                    'message' => "[{$regionCode}] Exception: " . $e->getMessage(),
                ];
            }

            $messages[] = (string) ($result['message'] ?? "[{$regionCode}] Unknown sync result.");
            if (!(bool) ($result['ok'] ?? false)) {
                $failed[] = $regionCode;
            }
        }

        $summary = implode(' | ', array_filter($messages, fn ($m) => trim((string) $m) !== ''));
        if (!empty($failed)) {
            return [
                'ok' => false,
                'message' => 'Region sync failed for: ' . implode(', ', $failed) . ($summary !== '' ? ' | ' . $summary : ''),
            ];
        }

        return [
            'ok' => true,
            'message' => $summary !== '' ? $summary : 'All configured regions synced.',
        ];
    }

    private function syncSingleRegion(
        int $days,
        ?string $endBefore,
        int $maxPages,
        int $itemsLimit,
        int $addressLimit,
        string $region,
        ?string $source = null
    ): array {
        $regionService = new RegionConfigService();
        $officialSpApiService = $this->officialSpApiService ?? new OfficialSpApiService($regionService);

        $createdBefore = $this->normalizeEndBefore($endBefore);
        $createdAfter = (new \DateTime($createdBefore))->modify("-{$days} days")->format('Y-m-d\TH:i:s\Z');

        $run = $this->startSyncRun($region, $days, $maxPages, $itemsLimit, $addressLimit, $createdAfter, $createdBefore, $source);

        $marketplaceService = new MarketplaceService();
        $ordersApi = new OfficialOrderAdapter($officialSpApiService, $marketplaceService, $regionService);
        $marketplaceTimezoneService = new MarketplaceTimezoneService();
        $orderNetValueService = new OrderNetValueService();
        $marketplaceCountryMap = $marketplaceService->getMarketplaceMap();
        $marketplaceIds = $marketplaceService->getMarketplaceIdsForRegion($region);

        $nextToken = null;
        $page = 0;
        $totalOrders = 0;
        $itemsFetched = 0;
        $addressesFetched = 0;
        $itemsFailed = 0;
        $addressesFailed = 0;
        $geocoded = 0;

        if (empty($marketplaceIds)) {
            $result = [
                'ok' => false,
                'message' => "[{$region}] No marketplace IDs configured.",
            ];
            $this->finishSyncRun($run, $result['ok'], $result['message'], $totalOrders, $itemsFetched, $addressesFetched, $geocoded);
            return $result;
        }

        do {
            $page++;
            $response = $ordersApi->getOrders($createdAfter, $createdBefore, $nextToken, $region);

            if (!$response) {
                $result = [
                    'ok' => false,
                    'message' => "[{$region}] Orders API error after retries.",
                ];
                $this->finishSyncRun($run, $result['ok'], $result['message'], $totalOrders, $itemsFetched, $addressesFetched, $geocoded);
                return $result;
            }

            if (($response['status'] ?? 500) >= 400) {
                Log::error('Orders sync error', [
                    'status' => $response['status'] ?? null,
                    'request_id' => $this->extractRequestIdFromHeaders((array) ($response['headers'] ?? [])),
                    'body' => $response['body'] ?? [],
                ]);
                $result = [
                    'ok' => false,
                    'message' => "[{$region}] Orders API error: " . (int) ($response['status'] ?? 500),
                ];
                $this->finishSyncRun($run, $result['ok'], $result['message'], $totalOrders, $itemsFetched, $addressesFetched, $geocoded);
                return $result;
            }

            $data = (array) ($response['body'] ?? []);
            $payload = $data['payload'] ?? [];
            $orders = $payload['Orders'] ?? [];
            $nextToken = $payload['NextToken'] ?? null;

            if (!empty($orders)) {
                $orderIds = array_values(array_filter(array_map(
                    static fn ($order) => (string) (($order['AmazonOrderId'] ?? '') ?: ''),
                    (array) $orders
                )));
                $existingOrders = empty($orderIds)
                    ? collect()
                    : Order::query()
                        ->whereIn('amazon_order_id', $orderIds)
                        ->get()
                        ->keyBy('amazon_order_id');

                $normalizedOrders = [];
                $rows = [];
                foreach ($orders as $order) {
                    $orderId = (string) ($order['AmazonOrderId'] ?? '');
                    if ($orderId === '') {
                        continue;
                    }
                    /** @var Order|null $existing */
                    $existing = $existingOrders->get($orderId);
                    $order = $this->mergeSummaryOrderWithExisting((array) $order, $existing);
                    $normalizedOrders[] = $order;

                    $ship = $order['ShippingAddress'] ?? [];
                    $paymentMethod = $order['PaymentMethodDetails'][0] ?? ($order['PaymentMethod'] ?? null);
                    $marketplaceId = $order['MarketplaceId'] ?? null;
                    $purchaseDate = $order['PurchaseDate'] ?? null;
                    if ($purchaseDate) {
                        try {
                            $purchaseDate = (new \DateTime($purchaseDate))->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            $purchaseDate = null;
                        }
                    }
                    $localized = $marketplaceTimezoneService->localizeFromUtc($order['PurchaseDate'] ?? null, $marketplaceId, $region);
                    $rows[] = [
                        'amazon_order_id' => $orderId,
                        'purchase_date' => $purchaseDate,
                        'purchase_date_local' => $localized['purchase_date_local'],
                        'purchase_date_local_date' => $localized['purchase_date_local_date'],
                        'order_status' => $order['OrderStatus'] ?? null,
                        'fulfillment_channel' => $order['FulfillmentChannel'] ?? null,
                        'payment_method' => $paymentMethod,
                        'sales_channel' => $order['SalesChannel'] ?? null,
                        'marketplace_id' => $marketplaceId,
                        'marketplace_timezone' => $localized['marketplace_timezone'],
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
                            'purchase_date_local',
                            'purchase_date_local_date',
                            'order_status',
                            'fulfillment_channel',
                            'payment_method',
                            'sales_channel',
                            'marketplace_id',
                            'marketplace_timezone',
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

                foreach ($normalizedOrders as $order) {
                    $orderId = $order['AmazonOrderId'] ?? null;
                    if (!$orderId) {
                        continue;
                    }

                    $status = $order['OrderStatus'] ?? null;
                    $needsDetails = $this->summaryMissingCriticalFields((array) $order)
                        || !$status
                        || !in_array($status, self::FINAL_STATUSES, true);
                    $itemsMissing = OrderItem::query()->where('amazon_order_id', $orderId)->count() === 0;
                    $orderItemsShipped = (int) ($order['NumberOfItemsShipped'] ?? 0);
                    $storedItemsShipped = (int) OrderItem::query()->where('amazon_order_id', $orderId)->sum('quantity_shipped');
                    $itemsShipmentOutdated = $orderItemsShipped > 0 && $storedItemsShipped < $orderItemsShipped;
                    $addressMissing = OrderShipAddress::query()->where('order_id', $orderId)->count() === 0;

                    if (($needsDetails || $itemsMissing || $itemsShipmentOutdated) && $itemsFetched < $itemsLimit) {
                        $itemsResponse = $ordersApi->getOrderItems($orderId, $region);

                        if ($itemsResponse && ($itemsResponse['status'] ?? 500) < 400) {
                            $itemsData = (array) ($itemsResponse['body'] ?? []);
                            $summary = (array) ($itemsData['payload']['OrderSummary'] ?? []);
                            if (!empty($summary)) {
                                $this->hydrateOrderFromSummary($orderId, $summary);
                            }
                            $items = $itemsData['payload']['OrderItems'] ?? [];
                            $isMarketplaceFacilitator = false;
                            foreach ($items as $item) {
                                $itemId = $item['OrderItemId'] ?? null;
                                if (!$itemId) {
                                    continue;
                                }
                                $marketplaceCountry = strtoupper(trim((string) ($marketplaceCountryMap[$order['MarketplaceId'] ?? ''] ?? '')));
                                $lineNet = $orderNetValueService->valuesFromApiItem($item, $marketplaceCountry);
                                $orderedQty = isset($item['QuantityOrdered']) ? (int) $item['QuantityOrdered'] : null;
                                $shippedQty = isset($item['QuantityShipped']) ? (int) $item['QuantityShipped'] : null;
                                $unshippedQty = null;
                                if (isset($item['QuantityUnshipped'])) {
                                    $unshippedQty = (int) $item['QuantityUnshipped'];
                                } elseif ($orderedQty !== null && $shippedQty !== null) {
                                    $unshippedQty = max(0, $orderedQty - $shippedQty);
                                }
                                OrderItem::updateOrCreate(
                                    ['order_item_id' => $itemId],
                                    [
                                        'amazon_order_id' => $orderId,
                                        'asin' => $item['ASIN'] ?? null,
                                        'seller_sku' => $item['SellerSKU'] ?? null,
                                        'title' => $item['Title'] ?? null,
                                        'quantity_ordered' => $orderedQty,
                                        'quantity_shipped' => $shippedQty,
                                        'quantity_unshipped' => $unshippedQty,
                                        'item_price_amount' => $item['ItemPrice']['Amount'] ?? null,
                                        'line_net_ex_tax' => $lineNet['line_net_ex_tax'],
                                        'item_price_currency' => $item['ItemPrice']['CurrencyCode'] ?? null,
                                        'line_net_currency' => $lineNet['line_net_currency'],
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
                            $orderNetValueService->refreshOrderNet($orderId);
                            $itemsFetched++;
                        } else {
                            $itemsFailed++;
                        }
                    }

                    if (($needsDetails || $addressMissing) && $addressesFetched < $addressLimit) {
                        $addrResponse = $ordersApi->getOrderAddress($orderId, $region);

                        if ($addrResponse && ($addrResponse['status'] ?? 500) < 400) {
                            $addrData = (array) ($addrResponse['body'] ?? []);
                            $summary = (array) ($addrData['payload']['OrderSummary'] ?? []);
                            if (!empty($summary)) {
                                $this->hydrateOrderFromSummary($orderId, $summary);
                            }
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
                        } else {
                            $addressesFailed++;
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

        $result = [
            'ok' => true,
            'message' => "[{$region}] Synced orders: {$totalOrders}, items fetched: {$itemsFetched}, addresses fetched: {$addressesFetched}, item detail failures: {$itemsFailed}, address detail failures: {$addressesFailed}, geocoded: {$geocoded}",
        ];
        $this->finishSyncRun($run, $result['ok'], $result['message'], $totalOrders, $itemsFetched, $addressesFetched, $geocoded);
        return $result;
    }

    private function summaryMissingCriticalFields(array $order): bool
    {
        $status = trim((string) ($order['OrderStatus'] ?? ''));
        $network = trim((string) ($order['FulfillmentChannel'] ?? ''));
        $method = trim((string) ($order['PaymentMethodDetails'][0] ?? ($order['PaymentMethod'] ?? '')));
        $ship = is_array($order['ShippingAddress'] ?? null) ? $order['ShippingAddress'] : [];
        $city = trim((string) ($ship['City'] ?? ''));
        $country = trim((string) ($ship['CountryCode'] ?? ''));
        $hasShippedCount = array_key_exists('NumberOfItemsShipped', $order) && is_numeric($order['NumberOfItemsShipped']);
        $hasUnshippedCount = array_key_exists('NumberOfItemsUnshipped', $order) && is_numeric($order['NumberOfItemsUnshipped']);
        $hasCounts = $hasShippedCount || $hasUnshippedCount;

        return $status === ''
            || $network === ''
            || $method === ''
            || !$hasCounts
            || $city === ''
            || $country === '';
    }

    private function mergeSummaryOrderWithExisting(array $incoming, ?Order $existing): array
    {
        if ($existing === null) {
            return $incoming;
        }

        $existingRaw = $this->decodeRawOrder($existing->raw_order);
        $existingShip = is_array($existingRaw['ShippingAddress'] ?? null) ? $existingRaw['ShippingAddress'] : [];
        $incomingShip = is_array($incoming['ShippingAddress'] ?? null) ? $incoming['ShippingAddress'] : [];

        $incoming['OrderStatus'] = $this->preferString($incoming['OrderStatus'] ?? null, $existingRaw['OrderStatus'] ?? $existing->order_status);
        $incoming['FulfillmentChannel'] = $this->preferString(
            $incoming['FulfillmentChannel'] ?? null,
            $existingRaw['FulfillmentChannel'] ?? $existing->fulfillment_channel
        );

        $incomingPaymentMethod = $this->preferString(
            $incoming['PaymentMethodDetails'][0] ?? ($incoming['PaymentMethod'] ?? null),
            $existingRaw['PaymentMethodDetails'][0] ?? ($existingRaw['PaymentMethod'] ?? $existing->payment_method)
        );
        $incoming['PaymentMethod'] = $incomingPaymentMethod;
        $incoming['PaymentMethodDetails'] = $incomingPaymentMethod !== null ? [$incomingPaymentMethod] : [];

        if (!array_key_exists('NumberOfItemsShipped', $incoming) && array_key_exists('NumberOfItemsShipped', $existingRaw)) {
            $incoming['NumberOfItemsShipped'] = $existingRaw['NumberOfItemsShipped'];
        }
        if (!array_key_exists('NumberOfItemsUnshipped', $incoming) && array_key_exists('NumberOfItemsUnshipped', $existingRaw)) {
            $incoming['NumberOfItemsUnshipped'] = $existingRaw['NumberOfItemsUnshipped'];
        }

        $incomingShip['City'] = $this->preferString($incomingShip['City'] ?? null, $existingShip['City'] ?? $existing->shipping_city);
        $incomingShip['CountryCode'] = $this->preferString($incomingShip['CountryCode'] ?? null, $existingShip['CountryCode'] ?? $existing->shipping_country_code);
        $incomingShip['PostalCode'] = $this->preferString($incomingShip['PostalCode'] ?? null, $existingShip['PostalCode'] ?? $existing->shipping_postal_code);
        $incomingShip['CompanyName'] = $this->preferString($incomingShip['CompanyName'] ?? null, $existingShip['CompanyName'] ?? $existing->shipping_company);
        $incomingShip['StateOrRegion'] = $this->preferString($incomingShip['StateOrRegion'] ?? null, $existingShip['StateOrRegion'] ?? $existing->shipping_region);
        $incoming['ShippingAddress'] = $incomingShip;

        return $incoming;
    }

    private function decodeRawOrder(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function preferString(mixed $primary, mixed $fallback): ?string
    {
        $first = trim((string) ($primary ?? ''));
        if ($first !== '') {
            return $first;
        }

        $second = trim((string) ($fallback ?? ''));

        return $second !== '' ? $second : null;
    }

    private function hydrateOrderFromSummary(string $orderId, array $summary): void
    {
        $order = Order::query()->where('amazon_order_id', $orderId)->first();
        if ($order === null) {
            return;
        }

        $raw = $order->raw_order;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        $ship = is_array($summary['ShippingAddress'] ?? null) ? $summary['ShippingAddress'] : [];
        $paymentMethod = trim((string) ($summary['PaymentMethodDetails'][0] ?? ($summary['PaymentMethod'] ?? '')));

        $status = trim((string) ($summary['OrderStatus'] ?? ''));
        $network = trim((string) ($summary['FulfillmentChannel'] ?? ''));
        $city = trim((string) ($ship['City'] ?? ''));
        $country = trim((string) ($ship['CountryCode'] ?? ''));
        $postal = trim((string) ($ship['PostalCode'] ?? ''));
        $company = trim((string) ($ship['CompanyName'] ?? ''));
        $region = trim((string) ($ship['StateOrRegion'] ?? ''));

        $updates = [];
        if ($status !== '') {
            $updates['order_status'] = $status;
            $raw['OrderStatus'] = $status;
        }
        if ($network !== '') {
            $updates['fulfillment_channel'] = $network;
            $raw['FulfillmentChannel'] = $network;
        }
        if ($paymentMethod !== '') {
            $updates['payment_method'] = $paymentMethod;
            $raw['PaymentMethod'] = $paymentMethod;
            $raw['PaymentMethodDetails'] = [$paymentMethod];
        }

        if (array_key_exists('NumberOfItemsShipped', $summary) && is_numeric($summary['NumberOfItemsShipped'])) {
            $raw['NumberOfItemsShipped'] = (int) $summary['NumberOfItemsShipped'];
        }
        if (array_key_exists('NumberOfItemsUnshipped', $summary) && is_numeric($summary['NumberOfItemsUnshipped'])) {
            $raw['NumberOfItemsUnshipped'] = (int) $summary['NumberOfItemsUnshipped'];
        }

        $currentShip = is_array($raw['ShippingAddress'] ?? null) ? $raw['ShippingAddress'] : [];
        if ($city !== '') {
            $updates['shipping_city'] = $city;
            $currentShip['City'] = $city;
        }
        if ($country !== '') {
            $updates['shipping_country_code'] = $country;
            $currentShip['CountryCode'] = $country;
        }
        if ($postal !== '') {
            $updates['shipping_postal_code'] = $postal;
            $currentShip['PostalCode'] = $postal;
        }
        if ($company !== '') {
            $updates['shipping_company'] = $company;
            $currentShip['CompanyName'] = $company;
        }
        if ($region !== '') {
            $updates['shipping_region'] = $region;
            $currentShip['StateOrRegion'] = $region;
        }
        $raw['ShippingAddress'] = $currentShip;

        $updates['raw_order'] = json_encode($raw);
        $updates['last_synced_at'] = now();

        Order::query()->where('amazon_order_id', $orderId)->update($updates);
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

    private function startSyncRun(
        string $region,
        int $days,
        int $maxPages,
        int $itemsLimit,
        int $addressLimit,
        string $createdAfter,
        string $createdBefore,
        ?string $source = null
    ): OrderSyncRun {
        return OrderSyncRun::create([
            'source' => $this->normalizeSource($source),
            'region' => strtoupper(trim($region)),
            'days' => $days,
            'max_pages' => $maxPages,
            'items_limit' => $itemsLimit,
            'address_limit' => $addressLimit,
            'created_after_at' => $this->parseSyncTime($createdAfter),
            'created_before_at' => $this->parseSyncTime($createdBefore),
            'started_at' => now(),
            'status' => 'running',
        ]);
    }

    private function finishSyncRun(
        ?OrderSyncRun $run,
        bool $ok,
        string $message,
        int $ordersSynced,
        int $itemsFetched,
        int $addressesFetched,
        int $geocoded
    ): void {
        if ($run === null) {
            return;
        }

        $run->update([
            'finished_at' => now(),
            'status' => $ok ? 'success' : 'failed',
            'message' => $message,
            'orders_synced' => max(0, $ordersSynced),
            'items_fetched' => max(0, $itemsFetched),
            'addresses_fetched' => max(0, $addressesFetched),
            'geocoded' => max(0, $geocoded),
        ]);
    }

    private function parseSyncTime(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeSource(?string $source): string
    {
        $source = strtolower(trim((string) $source));
        if ($source !== '') {
            return substr($source, 0, 32);
        }

        return app()->runningInConsole() ? 'console' : 'web';
    }

    private function extractRequestIdFromHeaders(array $headers): ?string
    {
        $requestId = (string) ($headers['x-amzn-RequestId'][0]
            ?? $headers['x-amzn-requestid'][0]
            ?? $headers['x-amz-request-id'][0]
            ?? $headers['X-Amzn-RequestId'][0]
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
