<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use SellingPartnerApi\SellingPartnerApi;
use DateTime;
use Illuminate\Support\Facades\Log;
use App\Services\MarketplaceService;
use App\Services\OrderNetValueService;
use App\Services\MarketplaceTimezoneService;
use App\Services\RegionConfigService;
use App\Models\CityGeo;
use App\Models\PostalCodeGeo;
use App\Models\OrderShipAddress;
use App\Models\AmazonOrderFeeLine;
use App\Models\OrderFeeEstimateLine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderQueryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Jobs\SyncOrdersJob;

class OrderController extends Controller
{
    private $connector;
    private $marketplaceService;
    private $orderQueryService;
    private $marketplaceTimezoneService;
    private $orderNetValueService;
    private const ORDERS_MAX_RETRIES = 3;

    public function __construct()
    {
        $regionService = new RegionConfigService();
        $regionConfig = $regionService->spApiConfig();
        $endpoint = $regionService->spApiEndpointEnum();

        $this->connector = SellingPartnerApi::seller(
            clientId: (string) $regionConfig['client_id'],
            clientSecret: (string) $regionConfig['client_secret'],
            refreshToken: (string) $regionConfig['refresh_token'],
            endpoint: $endpoint
        );
        $this->marketplaceService = new MarketplaceService();
        $this->orderQueryService = new OrderQueryService();
        $this->marketplaceTimezoneService = new MarketplaceTimezoneService();
        $this->orderNetValueService = new OrderNetValueService();
    }

    public function index(Request $request)
    {
        $responseHeaders = []; // Initialize to an empty array

        try {
            // Optional date filter (UI-controlled)
            $createdAfterInput = $request->input('created_after');
            $createdAfter = $createdAfterInput
                ? $this->orderQueryService->normalizeCreatedAfter($createdAfterInput)
                : null;
            $createdBeforeInput = $request->input('created_before');
            $createdBefore = $createdBeforeInput
                ? $this->orderQueryService->normalizeCreatedBefore($createdBeforeInput)
                : now()->subMinutes(2)->format('Y-m-d\TH:i:s\Z');
            
            $marketplacesUi = $this->marketplaceService->getMarketplacesForUi($this->connector);
            $countries = [];
            foreach ($marketplacesUi as $marketplaceId => $marketplace) {
                $countryCode = $marketplace['countryCode'] ?? $marketplace['country'] ?? '';
                if ($countryCode === '') {
                    continue;
                }
                if (!isset($countries[$countryCode])) {
                    $countries[$countryCode] = [
                        'country' => $countryCode,
                        'flag' => $marketplace['flag'] ?? '',
                        'flagUrl' => 'https://flagcdn.com/24x18/' . strtolower($countryCode) . '.png',
                        'marketplaceIds' => [],
                    ];
                }
                $countries[$countryCode]['marketplaceIds'][] = $marketplaceId;
            }
            if (!empty($countries)) {
                ksort($countries);
            }

            $selectedCountries = $request->input('countries', []);
            $dbEmpty = false;

        $query = $this->orderQueryService->buildQuery($request, $countries);

            // Capture dynamic filter options before applying filters
            $statusOptions = $query->clone()->select('order_status')->distinct()->pluck('order_status')->filter()->sort()->values()->all();
            $networkOptions = $query->clone()->select('fulfillment_channel')->distinct()->pluck('fulfillment_channel')->filter()->sort()->values()->all();
            $methodOptions = $query->clone()->select('payment_method')->distinct()->pluck('payment_method')->filter()->sort()->values()->all();

            $selectedStatus = $request->input('status');
            $selectedNetwork = $request->input('network');
            $selectedMethod = $request->input('method');
            $selectedB2b = $request->input('b2b');

            $perPage = (int) $request->input('per_page', 25);
            $perPage = max(10, min($perPage, 200));
            $ordersPaginator = $query
                ->orderByRaw('COALESCE(purchase_date_local, purchase_date) desc')
                ->orderBy('id', 'desc')
                ->paginate($perPage)
                ->appends($request->query());

            $orderModels = $ordersPaginator->getCollection();
            $orderIdsOnPage = $orderModels
                ->pluck('amazon_order_id')
                ->filter()
                ->values()
                ->all();

            $itemNetFallbackRows = [];
            if (!empty($orderIdsOnPage)) {
                $itemNetFallbackRows = DB::table('order_items')
                    ->selectRaw("
                        amazon_order_id,
                        SUM(COALESCE(line_net_ex_tax, 0)) as item_net_total,
                        MAX(COALESCE(line_net_currency, item_price_currency, '')) as item_net_currency
                    ")
                    ->whereIn('amazon_order_id', $orderIdsOnPage)
                    ->groupBy('amazon_order_id')
                    ->get();
            }

            $feeFallbackRows = [];
            if (!empty($orderIdsOnPage) && Schema::hasTable('amazon_order_fee_lines')) {
                $feeFallbackRows = DB::table('amazon_order_fee_lines')
                    ->selectRaw("
                        amazon_order_id,
                        MAX(COALESCE(currency, '')) as fee_currency,
                        SUM(COALESCE(amount, 0)) as fee_total
                    ")
                    ->whereIn('amazon_order_id', $orderIdsOnPage)
                    ->groupBy('amazon_order_id')
                    ->get();
            }

            $itemNetFallbackMap = [];
            foreach ($itemNetFallbackRows as $row) {
                $orderId = (string) ($row->amazon_order_id ?? '');
                if ($orderId === '') {
                    continue;
                }
                $total = (float) ($row->item_net_total ?? 0);
                $currency = strtoupper(trim((string) ($row->item_net_currency ?? '')));
                if ($total <= 0) {
                    continue;
                }
                $itemNetFallbackMap[$orderId] = [
                    'amount' => round($total, 2),
                    'currency' => $currency !== '' ? $currency : null,
                    'source' => 'line_items_fallback',
                ];
            }

            $fallbackOrderIds = $orderModels
                ->filter(fn (Order $order) => $order->is_marketplace_facilitator === null)
                ->pluck('amazon_order_id')
                ->filter()
                ->values()
                ->all();
            $mfFallbackMap = $this->buildMarketplaceFacilitatorMap($fallbackOrderIds);
            $feeFallbackMap = [];
            foreach ($feeFallbackRows as $row) {
                $orderId = (string) ($row->amazon_order_id ?? '');
                if ($orderId === '') {
                    continue;
                }
                $feeFallbackMap[$orderId] = [
                    'amount' => (float) ($row->fee_total ?? 0),
                    'currency' => strtoupper(trim((string) ($row->fee_currency ?? ''))),
                ];
            }

            $allOrders = $orderModels->map(function (Order $order) use ($mfFallbackMap, $itemNetFallbackMap, $feeFallbackMap) {
                $raw = $order->raw_order;
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    $raw = is_array($decoded) ? $decoded : [];
                }
                $fallbackNet = $itemNetFallbackMap[$order->amazon_order_id] ?? null;
                $netAmount = $order->order_net_ex_tax;
                $netCurrency = $order->order_net_ex_tax_currency ?: $order->order_total_currency;
                $netSource = $order->order_net_ex_tax_source;
                if (($netAmount === null || (float) $netAmount <= 0) && is_array($fallbackNet)) {
                    $netAmount = $fallbackNet['amount'];
                    $netCurrency = $fallbackNet['currency'] ?: $netCurrency;
                    $netSource = $fallbackNet['source'];
                }
                $feeAmount = $order->amazon_fee_total;
                $feeCurrency = $order->amazon_fee_currency ?: $netCurrency;
                $feeSource = 'finance_total';
                if ($feeAmount === null && $order->amazon_fee_estimated_total !== null) {
                    $feeAmount = $order->amazon_fee_estimated_total;
                    $feeCurrency = $order->amazon_fee_estimated_currency ?: $feeCurrency;
                    $feeSource = 'estimated_product_fees';
                }
                if ($feeAmount === null && isset($feeFallbackMap[$order->amazon_order_id])) {
                    $feeAmount = $feeFallbackMap[$order->amazon_order_id]['amount'];
                    $fallbackCurrency = $feeFallbackMap[$order->amazon_order_id]['currency'];
                    if ($fallbackCurrency !== '') {
                        $feeCurrency = $fallbackCurrency;
                    }
                    $feeSource = 'finance_lines_fallback';
                }

                if (!is_array($raw) || empty($raw)) {
                    $raw = [
                        'AmazonOrderId' => $order->amazon_order_id,
                        'PurchaseDate' => $order->purchase_date ? $order->purchase_date->format('c') : null,
                        'PurchaseDateLocal' => $order->purchase_date_local ? $order->purchase_date_local->format('c') : null,
                        'MarketplaceTimezone' => $order->marketplace_timezone,
                        'OrderStatus' => $order->order_status,
                        'FulfillmentChannel' => $order->fulfillment_channel,
                        'PaymentMethodDetails' => $order->payment_method ? [$order->payment_method] : [],
                        'OrderTotal' => [
                            'Amount' => $order->order_total_amount,
                            'CurrencyCode' => $order->order_total_currency,
                        ],
                        'OrderNetExTax' => [
                            'Amount' => $netAmount,
                            'CurrencyCode' => $netCurrency,
                            'Source' => $netSource,
                        ],
                        'AmazonFees' => [
                            'Amount' => $feeAmount,
                            'CurrencyCode' => $feeCurrency,
                            'Source' => $feeSource,
                        ],
                        'SalesChannel' => $order->sales_channel,
                        'MarketplaceId' => $order->marketplace_id,
                        'IsBusinessOrder' => $order->is_business_order,
                        'IsMarketplaceFacilitator' => $order->is_marketplace_facilitator ?? ($mfFallbackMap[$order->amazon_order_id] ?? null),
                        'ShippingAddress' => [
                            'City' => $order->shipping_city,
                            'CountryCode' => $order->shipping_country_code,
                            'PostalCode' => $order->shipping_postal_code,
                            'CompanyName' => $order->shipping_company,
                            'StateOrRegion' => $order->shipping_region,
                        ],
                    ];
                } else {
                    $raw['IsMarketplaceFacilitator'] = $order->is_marketplace_facilitator ?? ($mfFallbackMap[$order->amazon_order_id] ?? null);
                    $raw['PurchaseDateLocal'] = $order->purchase_date_local ? $order->purchase_date_local->format('c') : ($raw['PurchaseDateLocal'] ?? null);
                    $raw['MarketplaceTimezone'] = $order->marketplace_timezone ?? ($raw['MarketplaceTimezone'] ?? null);
                    $raw['OrderNetExTax'] = [
                        'Amount' => $netAmount,
                        'CurrencyCode' => $netCurrency ?: ($raw['OrderNetExTax']['CurrencyCode'] ?? $order->order_total_currency ?? null),
                        'Source' => $netSource,
                    ];
                    $raw['AmazonFees'] = [
                        'Amount' => $feeAmount,
                        'CurrencyCode' => $feeCurrency
                            ?: ($raw['AmazonFees']['CurrencyCode'] ?? $netCurrency ?? $order->order_total_currency ?? null),
                        'Source' => $feeSource,
                    ];
                }
                return $raw;
            })->values()->all();
            $postalGeoMap = $this->buildPostalGeoMapForOrders($allOrders);
            $cityGeoMap = $this->buildCityGeoMapForOrders($allOrders);

            $allOrders = array_map(function (array $order) use ($postalGeoMap, $cityGeoMap) {
                $ship = $order['ShippingAddress'] ?? [];
                $country = strtoupper(trim((string) ($ship['CountryCode'] ?? '')));
                $postal = strtoupper(trim((string) ($ship['PostalCode'] ?? '')));
                $key = $country !== '' && $postal !== '' ? "{$country}|{$postal}" : '';
                $geo = $key !== '' ? ($postalGeoMap[$key] ?? null) : null;
                $source = $geo ? 'postal' : null;

                if ($geo === null) {
                    $city = trim((string) ($ship['City'] ?? ''));
                    $region = trim((string) ($ship['StateOrRegion'] ?? ''));
                    if ($country !== '' && $city !== '') {
                        $cityKey = CityGeo::lookupHash($country, $city, $region);
                        $geo = $cityGeoMap[$cityKey] ?? null;
                        if ($geo !== null) {
                            $source = 'city';
                        }
                    }
                }

                $order['Geocode'] = [
                    'exists' => $geo !== null,
                    'lat' => $geo['lat'] ?? null,
                    'lng' => $geo['lng'] ?? null,
                    'source' => $source,
                ];

                return $order;
            }, $allOrders);

            $dbEmpty = $ordersPaginator->total() === 0;
            $oldestDate = Order::query()->selectRaw('MIN(COALESCE(purchase_date_local_date, DATE(purchase_date))) as d')->value('d');
            $newestDate = Order::query()->selectRaw('MAX(COALESCE(purchase_date_local_date, DATE(purchase_date))) as d')->value('d');

            $lastOrderSyncRun = null;
            if (Schema::hasTable('order_sync_runs')) {
                $lastOrderSyncRun = DB::table('order_sync_runs')
                    ->select(['started_at', 'finished_at', 'status', 'region', 'source'])
                    ->orderByDesc('id')
                    ->first();
            }

            return view('orders.index', [
                'orders' => array_values($allOrders), // Re-index array after filter
                'lastOrderSyncRun' => $lastOrderSyncRun,
                'ordersPaginator' => $ordersPaginator,
                'perPage' => $perPage,
                'responseHeaders' => $responseHeaders, // Pass the headers
                'marketplaces' => $marketplacesUi,
                'countries' => $countries,
                'selectedCountries' => $selectedCountries,
                'selectedStatus' => $selectedStatus,
                'selectedNetwork' => $selectedNetwork,
                'selectedMethod' => $selectedMethod,
                'selectedB2b' => $selectedB2b,
                'statusOptions' => $statusOptions,
                'networkOptions' => $networkOptions,
                'methodOptions' => $methodOptions,
                'dbEmpty' => $dbEmpty,
                'oldestDate' => $oldestDate,
                'newestDate' => $newestDate,
            ]);

        } catch (\Exception $e) {
            
            Log::error("Error fetching orders: " . $e->getMessage()); // Log exceptions
            return view('orders.error', [
                'error' => $e->getMessage(),
                'exception' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Display the specified order.
     */
    
    public function show(Request $request, $order_id)
    {
        $orderRecord = Order::query()->where('amazon_order_id', $order_id)->first();
        $items = OrderItem::query()->where('amazon_order_id', $order_id)->get();
        $address = OrderShipAddress::query()->where('order_id', $order_id)->first();
        $marketplaceCountryCode = null;
        if ($orderRecord && !empty($orderRecord->marketplace_id)) {
            $marketplaceCountryCode = DB::table('marketplaces')
                ->where('id', (string) $orderRecord->marketplace_id)
                ->value('country_code');
        }

        $ordersApi = $this->connector->ordersV0();
        $needsOrder = !$orderRecord;
        $needsItems = $items->isEmpty() || $this->needsItemShipmentRefresh($orderRecord, $items);
        $needsAddress = !$address;

        if ($needsOrder || $needsItems || $needsAddress) {
            if ($needsOrder) {
                $response = $ordersApi->getOrder($order_id);
                $orderData = $response->json();
                if ($response->status() < 400) {
                    $order = $orderData['payload'] ?? [];
                    if (!empty($order)) {
                        $ship = $order['ShippingAddress'] ?? [];
                        $marketplaceId = $order['MarketplaceId'] ?? null;
                        $localized = $this->marketplaceTimezoneService->localizeFromUtc(
                            $order['PurchaseDate'] ?? null,
                            $marketplaceId,
                            null
                        );
                        Order::updateOrCreate(
                            ['amazon_order_id' => $order_id],
                            [
                                'purchase_date' => $this->normalizePurchaseDate($order['PurchaseDate'] ?? null),
                                'purchase_date_local' => $localized['purchase_date_local'],
                                'purchase_date_local_date' => $localized['purchase_date_local_date'],
                                'order_status' => $order['OrderStatus'] ?? null,
                                'fulfillment_channel' => $order['FulfillmentChannel'] ?? null,
                                'payment_method' => $order['PaymentMethodDetails'][0] ?? ($order['PaymentMethod'] ?? null),
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
                            ]
                        );
                        if (!empty($marketplaceId) && empty($marketplaceCountryCode)) {
                            $marketplaceCountryCode = DB::table('marketplaces')
                                ->where('id', (string) $marketplaceId)
                                ->value('country_code');
                        }
                    }
                }
            }

            if ($needsItems) {
                $responseItems = $ordersApi->getOrderItems($order_id);
                if ($responseItems->status() < 400) {
                    $itemsData = $responseItems->json();
                    $itemsPayload = $itemsData['payload']['OrderItems'] ?? [];
                    $isMarketplaceFacilitator = false;
                    foreach ($itemsPayload as $item) {
                        $itemId = $item['OrderItemId'] ?? null;
                        if (!$itemId) {
                            continue;
                        }
                        $lineNet = $this->orderNetValueService->valuesFromApiItem($item, $marketplaceCountryCode);
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
                                'amazon_order_id' => $order_id,
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
                                'raw_item' => $item,
                            ]
                        );

                        if (!$isMarketplaceFacilitator && $this->isMarketplaceFacilitatorItem($item)) {
                            $isMarketplaceFacilitator = true;
                        }
                    }

                    Order::query()
                        ->where('amazon_order_id', $order_id)
                        ->update(['is_marketplace_facilitator' => $isMarketplaceFacilitator]);
                    $this->orderNetValueService->refreshOrderNet($order_id);
                }
            }

            if ($needsAddress) {
                $responseAddress = $ordersApi->getOrderAddress($order_id);
                if ($responseAddress->status() < 400) {
                    $addrData = $responseAddress->json();
                    $addr = $addrData['payload']['ShippingAddress'] ?? [];
                    OrderShipAddress::updateOrCreate(
                        ['order_id' => $order_id],
                        [
                            'country_code' => $addr['CountryCode'] ?? null,
                            'postal_code' => $addr['PostalCode'] ?? null,
                            'city' => $addr['City'] ?? null,
                            'region' => $addr['StateOrRegion'] ?? null,
                            'raw_address' => $addr,
                        ]
                    );
                }
            }

            $orderRecord = Order::query()->where('amazon_order_id', $order_id)->first();
            $items = OrderItem::query()->where('amazon_order_id', $order_id)->get();
            $address = OrderShipAddress::query()->where('order_id', $order_id)->first();
        }

        $itemsArray = $items->map(function ($i) {
            if (is_array($i->raw_item)) {
                return $i->raw_item;
            }
            if (is_string($i->raw_item)) {
                $decoded = json_decode($i->raw_item, true);
                return is_array($decoded) ? $decoded : [];
            }
            return [];
        })->filter()->values()->all();

        $orderArray = [];
        if ($orderRecord) {
            if (is_array($orderRecord->raw_order)) {
                $orderArray = $orderRecord->raw_order;
            } elseif (is_string($orderRecord->raw_order)) {
                $decoded = json_decode($orderRecord->raw_order, true);
                $orderArray = is_array($decoded) ? $decoded : [];
            }
        }

        $addressArray = [];
        if ($address) {
            if (is_array($address->raw_address)) {
                $addressArray = $address->raw_address;
            } elseif (is_string($address->raw_address)) {
                $decoded = json_decode($address->raw_address, true);
                $addressArray = is_array($decoded) ? $decoded : [];
            }
        }

        $marketplacesUi = $this->marketplaceService->getMarketplacesForUi($this->connector);
        $feeLines = AmazonOrderFeeLine::query()
            ->where('amazon_order_id', $order_id)
            ->orderBy('posted_date')
            ->orderBy('id')
            ->get();
        $estimatedFeeLines = collect();
        if (Schema::hasTable('order_fee_estimate_lines')) {
            $estimatedFeeLines = OrderFeeEstimateLine::query()
                ->where('amazon_order_id', $order_id)
                ->orderBy('estimated_at')
                ->orderBy('id')
                ->get();
        }

        return view('orders.show', [
            'order' => $orderArray,
            'orderRecord' => $orderRecord,
            'items' => $itemsArray,
            'address' => $addressArray,
            'feeLines' => $feeLines,
            'estimatedFeeLines' => $estimatedFeeLines,
            'marketplaces' => $marketplacesUi,
        ]);
    }

    /**
     * Get marketplace participation information from Amazon API
     */
    public function marketplaces(Request $request)
    {
        try {
            $marketplaces = $this->marketplaceService->getMarketplacesForUi($this->connector);
            $marketplaces = $this->mergeConfiguredMarketplaces($marketplaces);
            $marketplacesByRegion = $this->groupMarketplacesByRegion($marketplaces);

            return view('marketplaces', [
                'marketplaces' => $marketplaces,
                'marketplacesByRegion' => $marketplacesByRegion,
                'raw' => [],
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function mapData(Request $request)
    {
        $runLiveGeocoding = false;

        $marketplacesUi = $this->marketplaceService->getMarketplacesForUi($this->connector);
        $countries = [];
        foreach ($marketplacesUi as $marketplaceId => $marketplace) {
            $countryCode = $marketplace['countryCode'] ?? $marketplace['country'] ?? '';
            if ($countryCode === '') {
                continue;
            }
            if (!isset($countries[$countryCode])) {
                $countries[$countryCode] = [
                    'marketplaceIds' => [],
                ];
            }
            $countries[$countryCode]['marketplaceIds'][] = $marketplaceId;
        }

        $query = $this->buildOrdersQuery($request, $countries);
        $totalOrders = (clone $query)->count();

        $joinQuery = (clone $query)
            ->leftJoin('order_ship_addresses as osa', 'osa.order_id', '=', 'orders.amazon_order_id');

        $countryExpr = "coalesce(osa.country_code, orders.shipping_country_code)";
        $postalExpr = "coalesce(osa.postal_code, orders.shipping_postal_code)";
        $cityExpr = "coalesce(osa.city, orders.shipping_city)";
        $regionExpr = "coalesce(osa.region, orders.shipping_region)";

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            $orderIdsExpr = "string_agg(orders.amazon_order_id, '|')";
        } elseif ($driver === 'sqlsrv') {
            $orderIdsExpr = "string_agg(orders.amazon_order_id, '|')";
        } else {
            $orderIdsExpr = "group_concat(orders.amazon_order_id, '|')";
        }

        $groupRows = (clone $joinQuery)
            ->selectRaw("upper($countryExpr) as country")
            ->selectRaw("upper($postalExpr) as postal")
            ->selectRaw("min($cityExpr) as city")
            ->selectRaw("min($regionExpr) as region")
            ->selectRaw("count(*) as order_count")
            ->selectRaw("$orderIdsExpr as order_ids")
            ->whereRaw("($countryExpr is not null and trim($countryExpr) != '' and $postalExpr is not null and trim($postalExpr) != '')")
            ->groupBy(DB::raw("upper($countryExpr)"), DB::raw("upper($postalExpr)"))
            ->get();

        $missingQuery = (clone $joinQuery)
            ->whereRaw("($countryExpr is null or trim($countryExpr) = '' or $postalExpr is null or trim($postalExpr) = '')");
        $missingPostal = (clone $missingQuery)->count();
        $missingPostalOrderIds = (clone $missingQuery)
            ->limit(10)
            ->pluck('orders.amazon_order_id')
            ->all();

        $missingCityRows = (clone $missingQuery)
            ->selectRaw("upper($countryExpr) as country")
            ->selectRaw("upper($cityExpr) as city")
            ->selectRaw("upper($regionExpr) as region")
            ->selectRaw("count(*) as order_count")
            ->selectRaw("$orderIdsExpr as order_ids")
            ->whereRaw("($cityExpr is not null and trim($cityExpr) != '')")
            ->groupBy(DB::raw("upper($countryExpr)"), DB::raw("upper($cityExpr)"), DB::raw("upper($regionExpr)"))
            ->get();

        $byPostal = [];
        $byPostalPlace = [];
        foreach ($groupRows as $row) {
            $country = (string) ($row->country ?? '');
            $postal = (string) ($row->postal ?? '');
            if ($country === '' || $postal === '') {
                continue;
            }
            $key = $country . '|' . $postal;
            $orderIds = [];
            if (!empty($row->order_ids)) {
                $orderIds = array_values(array_filter(explode('|', $row->order_ids)));
                if (count($orderIds) > 20) {
                    $orderIds = array_slice($orderIds, 0, 20);
                }
            }
            $byPostal[$key] = [
                'country' => $country,
                'postal' => $postal,
                'orderIds' => $orderIds,
                'count' => (int) $row->order_count,
            ];
            $byPostalPlace[$key] = [
                'city' => $row->city ?: null,
                'region' => $row->region ?: null,
            ];
        }

        $groups = [];
        foreach ($byPostal as $entry) {
            $groups[$entry['country']][] = $entry['postal'];
        }

        $topPostalGroups = collect($byPostal)
            ->sortByDesc(fn (array $entry) => (int) ($entry['count'] ?? 0))
            ->take(5)
            ->map(fn (array $entry) => [
                'country' => (string) ($entry['country'] ?? ''),
                'postal' => (string) ($entry['postal'] ?? ''),
                'count' => (int) ($entry['count'] ?? 0),
            ])
            ->values()
            ->all();

        $neededCityGroups = [];
        foreach ($byPostal as $key => $entry) {
            $city = trim((string) ($byPostalPlace[$key]['city'] ?? ''));
            $region = trim((string) ($byPostalPlace[$key]['region'] ?? ''));
            if ($city === '') {
                continue;
            }

            $hash = CityGeo::lookupHash((string) $entry['country'], $city, $region);
            $neededCityGroups[$hash] = [
                'country' => (string) $entry['country'],
                'city' => $city,
                'region' => $region,
                'hash' => $hash,
            ];
        }

        foreach ($missingCityRows as $row) {
            $country = trim((string) ($row->country ?? ''));
            $city = trim((string) ($row->city ?? ''));
            $region = trim((string) ($row->region ?? ''));
            if ($country === '' || $city === '') {
                continue;
            }

            $hash = CityGeo::lookupHash($country, $city, $region);
            $neededCityGroups[$hash] = [
                'country' => $country,
                'city' => $city,
                'region' => $region,
                'hash' => $hash,
            ];
        }

        $cityGeoMap = [];
        if (!empty($neededCityGroups)) {
            $cityRows = CityGeo::query()
                ->whereIn('lookup_hash', array_keys($neededCityGroups))
                ->get();

            foreach ($cityRows as $row) {
                $cityGeoMap[(string) $row->lookup_hash] = $row;
            }
        }

        $geoMap = [];
        foreach ($groups as $country => $postals) {
            $rows = PostalCodeGeo::query()
                ->where('country_code', $country)
                ->whereIn('postal_code', $postals)
                ->get();
            foreach ($rows as $row) {
                $geoMap[$country . '|' . $row->postal_code] = $row;
            }
        }

        $geocoder = null;
        $newGeocodes = 0;
        $geocodeFailed = 0;
        $samplePostals = [];
        $cityFallbackPins = 0;
        $cityFallbackFailed = 0;
        $sampleCities = [];

        $points = [];
        foreach ($byPostal as $key => $entry) {
            $geo = $geoMap[$key] ?? null;

            if (!$geo && $geocoder) {
                $result = $geocoder->geocode($entry['country'], $entry['postal']);
                if ($result) {
                    $geo = PostalCodeGeo::updateOrCreate(
                        ['country_code' => $entry['country'], 'postal_code' => $entry['postal']],
                        ['lat' => $result['lat'], 'lng' => $result['lng'], 'source' => $result['source'] ?? null]
                    );
                    $newGeocodes++;
                } else {
                    $geocodeFailed++;
                    if (count($samplePostals) < 5) {
                        $samplePostals[] = $entry['country'] . ' ' . $entry['postal'];
                    }
                }
            }

            if ($geo) {
                $lat = (float) $geo->lat;
                $lng = (float) $geo->lng;
                if ($lat == 0.0 || $lng == 0.0 || abs($lat) > 90 || abs($lng) > 180) {
                    continue;
                }
                $points[] = [
                    'lat' => $lat,
                    'lng' => $lng,
                    'country' => $entry['country'],
                    'postal' => $entry['postal'],
                    'city' => $byPostalPlace[$key]['city'] ?? null,
                    'region' => $byPostalPlace[$key]['region'] ?? null,
                    'count' => $entry['count'],
                    'orderIds' => array_values(array_filter($entry['orderIds'])),
                ];
            } else {
                $city = $byPostalPlace[$key]['city'] ?? null;
                $region = $byPostalPlace[$key]['region'] ?? null;
                if ($city) {
                    $lookupHash = CityGeo::lookupHash($entry['country'], (string) $city, (string) $region);
                    $persistedCityGeo = $cityGeoMap[$lookupHash] ?? null;
                    if ($persistedCityGeo) {
                        $lat = (float) $persistedCityGeo->lat;
                        $lng = (float) $persistedCityGeo->lng;
                        if ($lat != 0.0 && $lng != 0.0 && abs($lat) <= 90 && abs($lng) <= 180) {
                            $points[] = [
                                'lat' => $lat,
                                'lng' => $lng,
                                'country' => $entry['country'],
                                'postal' => '',
                                'city' => $city,
                                'region' => $region ?: null,
                                'count' => $entry['count'],
                                'orderIds' => array_values(array_filter($entry['orderIds'])),
                                'label' => $city,
                            ];
                            $cityFallbackPins++;
                            continue;
                        }
                    }
                }

                if ($geocoder && $city) {
                    $geoCity = $geocoder->geocodeCity($entry['country'], (string) $city, $region);
                    if ($geoCity) {
                        $lat = (float) $geoCity['lat'];
                        $lng = (float) $geoCity['lng'];
                        if ($lat == 0.0 || $lng == 0.0 || abs($lat) > 90 || abs($lng) > 180) {
                            $cityFallbackFailed++;
                            if (count($sampleCities) < 5) {
                                $sampleCities[] = trim($entry['country'] . ' ' . $city);
                            }
                        } else {
                            $lookupHash = CityGeo::lookupHash($entry['country'], (string) $city, (string) $region);
                            CityGeo::updateOrCreate(
                                ['lookup_hash' => $lookupHash],
                                [
                                    'country_code' => CityGeo::normalizeCountry((string) $entry['country']),
                                    'city' => CityGeo::normalizeCity((string) $city),
                                    'region' => CityGeo::normalizeRegion((string) $region),
                                    'lat' => $lat,
                                    'lng' => $lng,
                                    'source' => $geoCity['source'] ?? null,
                                    'last_used_at' => now(),
                                ]
                            );
                            $points[] = [
                                'lat' => $lat,
                                'lng' => $lng,
                                'country' => $entry['country'],
                                'postal' => '',
                                'city' => $city,
                                'region' => $region ?: null,
                                'count' => $entry['count'],
                                'orderIds' => array_values(array_filter($entry['orderIds'])),
                                'label' => $city,
                            ];
                            $cityFallbackPins++;
                        }
                    } else {
                        $cityFallbackFailed++;
                        if (count($sampleCities) < 5) {
                            $sampleCities[] = trim($entry['country'] . ' ' . $city);
                        }
                    }
                }
            }
        }

        foreach ($missingCityRows as $row) {
            $country = (string) ($row->country ?? '');
            $city = (string) ($row->city ?? '');
            $region = (string) ($row->region ?? '');
            if ($country === '' || $city === '') {
                continue;
            }

            $orderIds = [];
            if (!empty($row->order_ids)) {
                $orderIds = array_values(array_filter(explode('|', $row->order_ids)));
                if (count($orderIds) > 20) {
                    $orderIds = array_slice($orderIds, 0, 20);
                }
            }

            $lookupHash = CityGeo::lookupHash($country, $city, $region);
            $persistedCityGeo = $cityGeoMap[$lookupHash] ?? null;
            if ($persistedCityGeo) {
                $geo = [
                    'lat' => (float) $persistedCityGeo->lat,
                    'lng' => (float) $persistedCityGeo->lng,
                ];
            } elseif ($geocoder) {
                $geo = $geocoder->geocodeCity($country, $city, $region);
                if ($geo) {
                    CityGeo::updateOrCreate(
                        ['lookup_hash' => $lookupHash],
                        [
                            'country_code' => CityGeo::normalizeCountry($country),
                            'city' => CityGeo::normalizeCity($city),
                            'region' => CityGeo::normalizeRegion($region),
                            'lat' => (float) $geo['lat'],
                            'lng' => (float) $geo['lng'],
                            'source' => $geo['source'] ?? null,
                            'last_used_at' => now(),
                        ]
                    );
                }
            } else {
                $geo = null;
            }

            if ($geo) {
                $points[] = [
                    'lat' => (float) $geo['lat'],
                    'lng' => (float) $geo['lng'],
                    'country' => $country,
                    'postal' => '',
                    'city' => $city,
                    'region' => $region ?: null,
                    'count' => (int) $row->order_count,
                    'orderIds' => $orderIds,
                    'label' => $city,
                ];
                $cityFallbackPins++;
            } else {
                $cityFallbackFailed++;
                if (count($sampleCities) < 5) {
                    $sampleCities[] = trim($country . ' ' . $city);
                }
            }
        }

        return response()->json([
            'points' => $points,
            'missingPostal' => $missingPostal,
            'missingPostalOrderIds' => array_values(array_filter($missingPostalOrderIds)),
            'geocodedThisRequest' => $newGeocodes,
            'geocodeFailed' => $geocodeFailed,
            'cityFallbackPins' => $cityFallbackPins,
            'cityFallbackFailed' => $cityFallbackFailed,
            'totalPostalGroups' => count($byPostal),
            'samplePostals' => $samplePostals,
            'sampleCities' => $sampleCities,
            'totalOrders' => $totalOrders,
            'liveGeocoding' => $runLiveGeocoding,
            'topPostalGroups' => $topPostalGroups,
        ]);
    }

    public function syncNow(Request $request)
    {
        try {
            if (!$this->amazonReachable()) {
                return redirect()
                    ->route('orders.index', $request->query())
                    ->with('sync_status', 'Sync failed: cannot reach Amazon API from this server.');
            }

            $days = (int) $request->input('days', 7);
            $days = max(1, min($days, 30));

            $createdBefore = now()->subMinutes(2)->format('Y-m-d\TH:i:s\Z');
            $createdAfter = (new DateTime($createdBefore))->modify("-{$days} days")->format('Y-m-d\TH:i:s\Z');
            $request->session()->put('orders_last_sync_request', [
                'type' => 'sync_now',
                'days' => $days,
                'created_after' => $createdAfter,
                'created_before' => $createdBefore,
                'end_before' => null,
            ]);

            SyncOrdersJob::dispatch($days, null, 5, 50, 50)
                ->onQueue('orders');

            return redirect()
                ->route('orders.index', array_merge($request->query(), ['page' => 1]))
                ->with('sync_status', 'Sync queued. It will run shortly.');
        } catch (\Exception $e) {
            Log::error('orders:sync failed', ['error' => $e->getMessage()]);
            return redirect()
                ->route('orders.index', array_merge($request->query(), ['page' => 1]))
                ->with('sync_status', 'Sync failed: ' . $e->getMessage());
        }
    }

    public function syncOlder(Request $request)
    {
        try {
            if (!$this->amazonReachable()) {
                return redirect()
                    ->route('orders.index', $request->query())
                    ->with('sync_status', 'Older sync failed: cannot reach Amazon API from this server.');
            }

            $oldest = Order::query()->orderBy('purchase_date', 'asc')->first();
            if (!$oldest || !$oldest->purchase_date) {
                return redirect()
                    ->route('orders.index', $request->query())
                    ->with('sync_status', 'No existing orders to anchor older sync.');
            }

            $endBefore = $oldest->purchase_date->modify('-1 second')->format('Y-m-d\TH:i:s\Z');
            $createdAfter = (new DateTime($endBefore))->modify('-30 days')->format('Y-m-d\TH:i:s\Z');
            $returnPage = (int) $request->input('return_page', 1);
            $returnPage = max(1, $returnPage);
            $perPage = (int) $request->input('per_page', 25);
            $perPage = max(10, min($perPage, 200));

            $request->session()->put('orders_last_sync_request', [
                'type' => 'sync_older',
                'days' => 30,
                'created_after' => $createdAfter,
                'created_before' => $endBefore,
                'end_before' => $endBefore,
                'anchor_order_id' => $oldest->amazon_order_id ?? null,
            ]);

            SyncOrdersJob::dispatch(30, $endBefore, 5, 50, 50)
                ->onQueue('orders');

            return redirect()
                ->route('orders.index', array_merge($request->query(), [
                    'page' => $returnPage,
                    'per_page' => $perPage,
                ]))
                ->with('sync_status', 'Older sync queued. It will run shortly.');
        } catch (\Exception $e) {
            Log::error('orders:sync older failed', ['error' => $e->getMessage()]);
            return redirect()
                ->route('orders.index', $request->query())
                ->with('sync_status', 'Older sync failed: ' . $e->getMessage());
        }
    }

    private function amazonReachable(): bool
    {
        try {
            $response = Http::timeout(3)->get('https://api.amazon.com');
            return $response->successful() || $response->status() === 403;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function buildOrdersQuery(Request $request, array $countries)
    {
        return $this->orderQueryService->buildQuery($request, $countries);
    }

    private function getOrdersWithRetry($ordersApi, string $createdAfter, string $createdBefore, array $marketplaceIds)
    {
        $attempt = 0;
        $lastResponse = null;

        while ($attempt <= self::ORDERS_MAX_RETRIES) {
            $lastResponse = $ordersApi->getOrders(
                createdAfter: $createdAfter,
                createdBefore: $createdBefore,
                marketplaceIds: $marketplaceIds
            );

            $status = $lastResponse->status();
            if ($status !== 429) {
                return $lastResponse;
            }

            $headers = $lastResponse->headers();
            $this->logRateLimits($headers);

            $retryAfter = $headers['Retry-After'] ?? $headers['retry-after'] ?? null;
            if (is_array($retryAfter)) {
                $retryAfter = $retryAfter[0] ?? null;
            }

            $attempt++;
            if ($attempt > self::ORDERS_MAX_RETRIES) {
                return $lastResponse;
            }

            $sleepSeconds = is_numeric($retryAfter) ? (int) $retryAfter : $this->backoffSeconds($attempt);
            $sleepSeconds = max(1, $sleepSeconds);
            sleep($sleepSeconds);
        }

        return $lastResponse;
    }

    private function backoffSeconds(int $attempt): int
    {
        $base = 2 ** $attempt;
        $jitter = random_int(0, 1000) / 1000;
        return (int) ceil($base + $jitter);
    }

    private function logRateLimits($headers): void
    {
        $headersArray = $this->normalizeHeaders($headers);

        $limit = $headersArray['x-amzn-RateLimit-Limit'] ?? $headersArray['X-Amzn-RateLimit-Limit'] ?? null;
        $reset = $headersArray['x-amzn-RateLimit-Reset'] ?? $headersArray['X-Amzn-RateLimit-Reset'] ?? null;
        $retryAfter = $headersArray['Retry-After'] ?? $headersArray['retry-after'] ?? null;

        if (is_array($limit)) {
            $limit = $limit[0] ?? null;
        }
        if (is_array($reset)) {
            $reset = $reset[0] ?? null;
        }
        if (is_array($retryAfter)) {
            $retryAfter = $retryAfter[0] ?? null;
        }

        if ($limit || $reset || $retryAfter) {
            Log::info('SP-API rate limit headers', [
                'rate_limit' => $limit,
                'rate_reset' => $reset,
                'retry_after' => $retryAfter,
            ]);
        }
    }

    private function normalizeHeaders($headers): array
    {
        if (is_array($headers)) {
            return $headers;
        }

        if (is_object($headers)) {
            if (method_exists($headers, 'all')) {
                return (array) $headers->all();
            }
            if (method_exists($headers, 'toArray')) {
                return (array) $headers->toArray();
            }
        }

        return [];
    }

    private function getRetryAfterSeconds($headers): int
    {
        $headersArray = $this->normalizeHeaders($headers);
        $retryAfter = $headersArray['Retry-After'] ?? $headersArray['retry-after'] ?? null;
        if (is_array($retryAfter)) {
            $retryAfter = $retryAfter[0] ?? null;
        }
        if (is_numeric($retryAfter)) {
            return max(1, (int) $retryAfter);
        }
        return $this->backoffSeconds(1);
    }


    private function normalizePurchaseDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        try {
            return (new \DateTime($value))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function needsItemShipmentRefresh(?Order $orderRecord, $items): bool
    {
        if (!$orderRecord || $items->isEmpty()) {
            return false;
        }

        $raw = $orderRecord->raw_order;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return false;
        }

        $orderShipped = (int) ($raw['NumberOfItemsShipped'] ?? 0);
        if ($orderShipped <= 0) {
            return false;
        }

        $itemShipped = (int) $items->sum(function (OrderItem $item) {
            return (int) ($item->quantity_shipped ?? 0);
        });

        return $itemShipped < $orderShipped;
    }

    private function buildMarketplaceFacilitatorMap(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $result = [];
        $items = OrderItem::query()
            ->whereIn('amazon_order_id', $orderIds)
            ->get(['amazon_order_id', 'raw_item']);

        foreach ($items as $item) {
            $orderId = (string) $item->amazon_order_id;
            if ($orderId === '' || !empty($result[$orderId])) {
                continue;
            }

            $raw = $item->raw_item;
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $raw = is_array($decoded) ? $decoded : [];
            }

            if (is_array($raw) && $this->isMarketplaceFacilitatorItem($raw)) {
                $result[$orderId] = true;
            }
        }

        foreach ($orderIds as $orderId) {
            if (!array_key_exists($orderId, $result)) {
                $result[$orderId] = false;
            }
        }

        return $result;
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

    private function buildPostalGeoMapForOrders(array $orders): array
    {
        $keys = [];
        $countries = [];
        $postals = [];

        foreach ($orders as $order) {
            $ship = is_array($order['ShippingAddress'] ?? null) ? $order['ShippingAddress'] : [];
            $country = strtoupper(trim((string) ($ship['CountryCode'] ?? '')));
            $postal = strtoupper(trim((string) ($ship['PostalCode'] ?? '')));
            if ($country === '' || $postal === '') {
                continue;
            }

            $key = "{$country}|{$postal}";
            $keys[$key] = true;
            $countries[$country] = true;
            $postals[$postal] = true;
        }

        if (empty($keys)) {
            return [];
        }

        $rows = PostalCodeGeo::query()
            ->whereIn('country_code', array_keys($countries))
            ->whereIn('postal_code', array_keys($postals))
            ->get(['country_code', 'postal_code', 'lat', 'lng']);

        $map = [];
        foreach ($rows as $row) {
            $country = strtoupper(trim((string) ($row->country_code ?? '')));
            $postal = strtoupper(trim((string) ($row->postal_code ?? '')));
            if ($country === '' || $postal === '') {
                continue;
            }
            $key = "{$country}|{$postal}";
            if (!isset($keys[$key])) {
                continue;
            }
            $map[$key] = [
                'lat' => $row->lat,
                'lng' => $row->lng,
            ];
        }

        return $map;
    }

    private function buildCityGeoMapForOrders(array $orders): array
    {
        if (!Schema::hasTable('city_geos')) {
            return [];
        }

        $needed = [];
        foreach ($orders as $order) {
            $ship = is_array($order['ShippingAddress'] ?? null) ? $order['ShippingAddress'] : [];
            $country = strtoupper(trim((string) ($ship['CountryCode'] ?? '')));
            $city = trim((string) ($ship['City'] ?? ''));
            $region = trim((string) ($ship['StateOrRegion'] ?? ''));
            if ($country === '' || $city === '') {
                continue;
            }

            $hash = CityGeo::lookupHash($country, $city, $region);
            $needed[$hash] = true;
        }

        if (empty($needed)) {
            return [];
        }

        $rows = CityGeo::query()
            ->whereIn('lookup_hash', array_keys($needed))
            ->get(['lookup_hash', 'lat', 'lng']);

        $map = [];
        foreach ($rows as $row) {
            $hash = (string) ($row->lookup_hash ?? '');
            if ($hash === '' || !isset($needed[$hash])) {
                continue;
            }
            $map[$hash] = [
                'lat' => $row->lat,
                'lng' => $row->lng,
            ];
        }

        return $map;
    }

    private function mergeConfiguredMarketplaces(array $marketplaces): array
    {
        $regionService = new RegionConfigService();
        $regions = $regionService->spApiRegions();
        $configuredRegionByMarketplaceId = [];

        foreach ($marketplaces as $marketplaceId => $marketplace) {
            $marketplace['source'] = 'api';
            $marketplaces[$marketplaceId] = $marketplace;
        }

        foreach ($regions as $region) {
            $config = $regionService->spApiConfig($region);
            $configuredIds = $config['marketplace_ids'] ?? [];

            foreach ($configuredIds as $marketplaceId) {
                $marketplaceId = trim((string) $marketplaceId);
                if ($marketplaceId === '') {
                    continue;
                }
                $configuredRegionByMarketplaceId[$marketplaceId] = strtoupper($region);

                if (isset($marketplaces[$marketplaceId])) {
                    continue;
                }

                [$countryCode, $name] = $this->configuredMarketplaceMeta($marketplaceId, $region);

                $marketplaces[$marketplaceId] = [
                    'id' => $marketplaceId,
                    'name' => $name,
                    'countryCode' => $countryCode,
                    'country' => $countryCode,
                    'defaultCurrency' => '',
                    'defaultLanguage' => '',
                    'flag' => '',
                    'region' => strtoupper($region),
                    'source' => 'fallback',
                ];
            }
        }

        foreach ($marketplaces as $marketplaceId => $marketplace) {
            $region = $configuredRegionByMarketplaceId[$marketplaceId]
                ?? $this->inferRegionFromCountryCode((string) ($marketplace['countryCode'] ?? ''));
            $marketplace['region'] = $region;
            if (!isset($marketplace['source']) || trim((string) $marketplace['source']) === '') {
                $marketplace['source'] = 'api';
            }
            $marketplaces[$marketplaceId] = $marketplace;
        }

        ksort($marketplaces);
        return $marketplaces;
    }

    private function configuredMarketplaceMeta(string $marketplaceId, string $region): array
    {
        $known = [
            'ATVPDKIKX0DER' => ['US', 'Amazon.com'],
            'A2EUQ1WTGCTBG2' => ['CA', 'Amazon.ca'],
            'A1AM78C64UM0Y8' => ['MX', 'Amazon.com.mx'],
            'A2Q3Y263D00KWC' => ['BR', 'Amazon.com.br'],
        ];

        if (isset($known[$marketplaceId])) {
            return $known[$marketplaceId];
        }

        $fallbackCountry = match (strtoupper($region)) {
            'NA' => 'NA',
            'FE' => 'FE',
            default => 'EU',
        };

        return [$fallbackCountry, "Configured {$region} marketplace"];
    }

    private function groupMarketplacesByRegion(array $marketplaces): array
    {
        $grouped = [
            'EU' => [],
            'NA' => [],
            'FE' => [],
            'OTHER' => [],
        ];

        foreach ($marketplaces as $marketplace) {
            $region = strtoupper(trim((string) ($marketplace['region'] ?? 'OTHER')));
            if (!isset($grouped[$region])) {
                $region = 'OTHER';
            }
            $grouped[$region][] = $marketplace;
        }

        foreach ($grouped as $region => $rows) {
            usort($rows, static function (array $a, array $b): int {
                $countryA = strtoupper(trim((string) ($a['countryCode'] ?? '')));
                $countryB = strtoupper(trim((string) ($b['countryCode'] ?? '')));
                if ($countryA !== $countryB) {
                    return $countryA <=> $countryB;
                }

                $idA = strtoupper(trim((string) ($a['id'] ?? '')));
                $idB = strtoupper(trim((string) ($b['id'] ?? '')));
                return $idA <=> $idB;
            });
            $grouped[$region] = $rows;
        }

        return array_filter($grouped, static fn (array $rows) => !empty($rows));
    }

    private function inferRegionFromCountryCode(string $countryCode): string
    {
        $countryCode = strtoupper(trim($countryCode));
        if (in_array($countryCode, ['US', 'CA', 'MX', 'BR'], true)) {
            return 'NA';
        }
        if (in_array($countryCode, ['JP', 'AU', 'SG', 'IN', 'TR', 'AE', 'SA', 'EG', 'ZA'], true)) {
            return 'FE';
        }
        if ($countryCode === '') {
            return 'OTHER';
        }
        return 'EU';
    }
}
