<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                Order {{ $order['AmazonOrderId'] ?? 'N/A' }}
            </h2>
            <a href="{{ route('orders.index') }}" class="inline-flex items-center px-3 py-2 rounded-md border border-gray-300 bg-white text-gray-800 text-sm">
                Back to Orders
            </a>
        </div>
    </x-slot>

    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        @if ($order)
            @php
                $status = $order['OrderStatus'] ?? 'N/A';
                $purchaseDate = $order['PurchaseDate'] ?? null;
                $fulfillment = $order['FulfillmentChannel'] ?? 'N/A';
                $salesChannel = $order['SalesChannel'] ?? 'N/A';
                $marketplaceId = $order['MarketplaceId'] ?? 'N/A';
                $marketplaceName = ($marketplaces[$marketplaceId]['name'] ?? '') ?: 'Unknown';
                $isB2b = !empty($order['IsBusinessOrder']);
                $totalAmount = $order['OrderTotal']['Amount'] ?? null;
                $totalCurrency = $order['OrderTotal']['CurrencyCode'] ?? null;
                $buyerEmail = $order['BuyerInfo']['BuyerEmail']
                    ?? $order['BuyerEmail']
                    ?? null;
                $companyName = $order['ShippingAddress']['CompanyName']
                    ?? $order['DefaultShipFromLocationAddress']['CompanyName']
                    ?? null;
                $formatDateTime = function ($value) {
                    if (!$value) {
                        return 'N/A';
                    }
                    try {
                        return (new DateTime($value))->format('D, M j, Y H:i');
                    } catch (Exception $e) {
                        return 'N/A';
                    }
                };
                $formatDateOnly = function ($value) {
                    if (!$value) {
                        return 'N/A';
                    }
                    try {
                        return (new DateTime($value))->format('D, M j, Y');
                    } catch (Exception $e) {
                        return 'N/A';
                    }
                };
            @endphp

            <div class="mb-6 p-4 rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center gap-3 mb-3">
                    <span class="text-sm px-2 py-1 rounded-md border" style="background:#e2e8f0;color:#1f2937;border-color:#cbd5e1;">
                        {{ $status }}
                    </span>
                    @if($isB2b)
                        <span class="text-sm px-2 py-1 rounded-md" style="background:#0b2a4a;color:#fff;">B2B</span>
                    @else
                        <span class="text-sm px-2 py-1 rounded-md" style="background:#f1f5f9;color:#334155;">Consumer</span>
                    @endif
                    <span class="text-sm text-gray-600">Marketplace: {{ $marketplaceId }} — {{ $marketplaceName }}</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <div class="text-xs text-gray-500">Purchased</div>
                        <div class="text-sm font-medium">
                            {{ $formatDateTime($purchaseDate) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Fulfillment</div>
                        <div class="text-sm font-medium">{{ $fulfillment }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Sales Channel</div>
                        <div class="text-sm font-medium">{{ $salesChannel }}</div>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <div class="text-xs text-gray-500">Buyer Email</div>
                        <div class="text-sm font-medium break-all">{{ $buyerEmail ?? 'N/A' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Company Name</div>
                        <div class="text-sm font-medium">{{ $companyName ?? 'N/A' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Last Update</div>
                        <div class="text-sm font-medium">{{ $formatDateTime($order['LastUpdateDate'] ?? null) }}</div>
                    </div>
                </div>

                <div class="mt-5 border-t border-gray-200 pt-4">
                    <div class="text-xs font-semibold text-gray-600 uppercase tracking-wide mb-3">Date Windows</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="p-3 rounded-md border border-gray-200 bg-gray-50">
                            <div class="text-xs text-gray-500 mb-1">Earliest Ship</div>
                            <div class="text-sm font-medium">{{ $formatDateOnly($order['EarliestShipDate'] ?? null) }}</div>
                        </div>
                        <div class="p-3 rounded-md border border-gray-200 bg-gray-50">
                            <div class="text-xs text-gray-500 mb-1">Latest Ship</div>
                            <div class="text-sm font-medium">{{ $formatDateOnly($order['LatestShipDate'] ?? null) }}</div>
                        </div>
                        <div class="p-3 rounded-md border border-gray-200 bg-gray-50">
                            <div class="text-xs text-gray-500 mb-1">Earliest Delivery</div>
                            <div class="text-sm font-medium">{{ $formatDateOnly($order['EarliestDeliveryDate'] ?? null) }}</div>
                        </div>
                        <div class="p-3 rounded-md border border-gray-200 bg-gray-50">
                            <div class="text-xs text-gray-500 mb-1">Latest Delivery</div>
                            <div class="text-sm font-medium">{{ $formatDateOnly($order['LatestDeliveryDate'] ?? null) }}</div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 flex items-baseline gap-2">
                    <div class="text-xs text-gray-500">Total</div>
                    <div class="text-lg font-semibold">
                        {{ $totalAmount ?? 'N/A' }} {{ $totalCurrency ?? '' }}
                    </div>
                </div>
            </div>

            <div class="p-4 rounded-lg border border-gray-200 bg-white shadow-sm mb-6">
                <div class="text-sm font-semibold mb-3">Items</div>
                @if (!empty($items))
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse" border="1" cellpadding="6" cellspacing="0">
                            <thead>
                                <tr class="bg-gray-100">
                                    <th>Image</th>
                                    <th>Title</th>
                                    <th>SKU</th>
                                    <th>ASIN</th>
                                    <th>Qty</th>
                                    <th>Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($items as $item)
                                    @php
                                        $imageUrl = $item['SmallImage']['URL']
                                            ?? $item['SmallImageUrl']
                                            ?? $item['Image']['URL']
                                            ?? $item['ImageUrl']
                                            ?? null;
                                    @endphp
                                    <tr>
                                        <td style="text-align:center;">
                                            @if ($imageUrl)
                                                <img src="{{ $imageUrl }}" alt="Item image" class="w-12 h-12 object-cover rounded" loading="lazy">
                                            @else
                                                <span class="text-xs text-gray-500">No image</span>
                                            @endif
                                        </td>
                                        <td>{{ $item['Title'] ?? 'N/A' }}</td>
                                        <td>{{ $item['SellerSKU'] ?? 'N/A' }}</td>
                                        <td>{{ $item['ASIN'] ?? 'N/A' }}</td>
                                        <td style="text-align:center;">{{ $item['QuantityOrdered'] ?? 'N/A' }}</td>
                                        <td>
                                            {{ $item['ItemPrice']['Amount'] ?? 'N/A' }}
                                            {{ $item['ItemPrice']['CurrencyCode'] ?? '' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-sm text-gray-600">No items available.</div>
                @endif
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="p-4 rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="text-sm font-semibold mb-3">Shipping</div>
                    @if (!empty($address))
                        @php
                            $ship = $address['ShippingAddress'] ?? $address;
                        @endphp
                        <div class="text-sm">
                            <div class="font-medium">{{ $ship['Name'] ?? 'N/A' }}</div>
                            @if (!empty($ship['CompanyName']))
                                <div>{{ $ship['CompanyName'] }}</div>
                            @endif
                            <div>{{ $ship['AddressLine1'] ?? '' }}</div>
                            @if (!empty($ship['AddressLine2']))
                                <div>{{ $ship['AddressLine2'] }}</div>
                            @endif
                            @if (!empty($ship['AddressLine3']))
                                <div>{{ $ship['AddressLine3'] }}</div>
                            @endif
                            <div>{{ $ship['City'] ?? '' }} {{ $ship['StateOrRegion'] ?? '' }} {{ $ship['PostalCode'] ?? '' }}</div>
                            <div>{{ $ship['CountryCode'] ?? '' }}</div>
                            @if (!empty($ship['Phone']))
                                <div class="mt-2 text-gray-600">Phone: {{ $ship['Phone'] }}</div>
                            @endif
                        </div>
                    @else
                        <div class="text-sm text-gray-600">No address available.</div>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="p-4 rounded-lg border border-gray-200 bg-white shadow-sm">
                    <details>
                        <summary class="cursor-pointer text-sm font-semibold">Raw Order JSON</summary>
                        <pre class="text-xs mt-3 bg-gray-50 p-3 rounded overflow-x-auto">{{ json_encode($order, JSON_PRETTY_PRINT) }}</pre>
                    </details>
                </div>
                <div class="p-4 rounded-lg border border-gray-200 bg-white shadow-sm">
                    <details>
                        <summary class="cursor-pointer text-sm font-semibold">Raw Items JSON</summary>
                        <pre class="text-xs mt-3 bg-gray-50 p-3 rounded overflow-x-auto">{{ json_encode($items, JSON_PRETTY_PRINT) }}</pre>
                    </details>
                </div>
            </div>
        @else
            <p>No order details available.</p>
        @endif
    </div>
</x-app-layout>
