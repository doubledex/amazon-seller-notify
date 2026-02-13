<?php

// require 'vendor/autoload.php';
require __DIR__ . '/../vendor/autoload.php';

use SellingPartnerApi\SellingPartnerApi;
use SellingPartnerApi\Enums\Endpoint;
use SellingPartnerApi\Seller\ServicesV1\Dto\Payload;

$lwaClientId = (string) getenv('LWA_CLIENT_ID');
$lwaClientSecret = (string) getenv('LWA_CLIENT_SECRET');
$spApiRefreshToken = (string) getenv('SP_API_REFRESH_TOKEN');

if ($lwaClientId === '' || $lwaClientSecret === '' || $spApiRefreshToken === '') {
    exit("Missing SP-API credentials. Set LWA_CLIENT_ID, LWA_CLIENT_SECRET, and SP_API_REFRESH_TOKEN in .env\n");
}

$connector = SellingPartnerApi::seller(
    clientId: $lwaClientId,
    clientSecret: $lwaClientSecret,
    refreshToken: $spApiRefreshToken,
    endpoint: Endpoint::EU,  // Or Endpoint::EU, Endpoint::FE, Endpoint::NA_SANDBOX, etc.
);

$ordersApi = $connector->ordersV0();
$response = $ordersApi->getOrders(
    createdAfter: ('2025-02-01'),
    marketplaceIds: ['A1F83G8C2ARO7P', 'A1PA6795UKMFR9','A1RKKUPIHCS9HS','A13V1IB3VIYZZH','AMEN7PMS3EDW','A1805IZSGTT6HS','APJ6JRA9NG5V4','A28R8C7NBKEWEA','A1C3SOZRARQ6R3','A1ZFFQZ3HTUKT9','A2NODRKZP88ZB9','A33AVAJ2PDY3EV','A38D8NSA03LJTC','A62U237T8HV6N','AFQLKURYRPEL8','AZMDEXL2RVFNN'],
);


// Check for API errors
if ($response->status() >= 400) {
    echo "API Error: " . $response->body() . "\n"; 
} else {
    $orderData = $response->json(); 

    $reportCreatedDate = (new DateTime($orderData['payload']['CreatedBefore']))->format('Y-m-d');
    $reportCreatedTime = (new DateTime($orderData['payload']['CreatedBefore']))->format('h:i:s');
    print_r("Report created date: " . $reportCreatedDate . " at " . $reportCreatedTime . "<br>");

    // Display orders in a table
    if (isset($orderData['payload']['Orders'])) {

        echo '<table border="1" cellpadding="2" cellspacing="0">';
        echo '<tr><th colspan="2">Purchase Date</th><th>Order Status</th><th>Order ID</th><th>Ship To</th><th>Network</th><th>Unshipped</th><th>Shipped</th><th>Method</th><th>Value</th><th>Currency</th><th>Ship to</th><th>MktPlc</th><th>Buyer Email</th></tr>';
        
        $reversedOrders = array_reverse($orderData['payload']['Orders']);

        foreach ($reversedOrders as $order) {
            
            $purchaseDate   = (new DateTime($order['PurchaseDate']))->format('Y-m-d');
            $purchaseTime   = (new DateTime($order['PurchaseDate']))->format('H:i:s');
            $buyerEmail     = isset($order['BuyerInfo']['BuyerEmail']) ? htmlspecialchars($order['BuyerInfo']['BuyerEmail']) : 'N/A';
            $orderValue     = isset($order['OrderTotal']['Amount']) ? htmlspecialchars($order['OrderTotal']['Amount']) : 'N/A';
            $paymentMethod  = isset($order['PaymentMethodDetails']['0']) ? htmlspecialchars($order['PaymentMethodDetails']['0']) : 'N/A';
            $shippTo        = isset($order['ShippingAddress']['City']) ? htmlspecialchars($order['ShippingAddress']['City']) : 'N/A';
            $itemsUnshipped = isset($order['NumberOfItemsUnshipped']) ? htmlspecialchars($order['NumberOfItemsUnshipped']) : '';
            $itemsShipped   = isset($order['NumberOfItemsShipped']) ? htmlspecialchars($order['NumberOfItemsShipped']) : '';
            $currencyCode   = isset($order['OrderTotal']['CurrencyCode']) ? htmlspecialchars($order['OrderTotal']['CurrencyCode']) : '';
            $country        = isset($order['ShippingAddress']['CountryCode']) ? htmlspecialchars($order['ShippingAddress']['CountryCode']) : '';

            echo '<tr>'
            . '<td>' . $purchaseDate . '</td>'
            . '<td>' . $purchaseTime . '</td>'
            . '<td>' . htmlspecialchars($order['OrderStatus']) . '</td>'
            . '<td>' . htmlspecialchars($order['AmazonOrderId']) . '</td>'
            . '<td>' . $shippTo . '</td>'
            . '<td>' . $order['FulfillmentChannel'] . '</td>'
            . '<td style="text-align: center;">' . $itemsUnshipped . '</td>'
            . '<td style="text-align: center;">' . $itemsShipped . '</td>'
            . '<td>' . $paymentMethod . '</td>'
            . '<td dir="rtl">' . $orderValue . '</td>'
            . '<td>' . $currencyCode . '</td>'
            . '<td>' . $country . '</td>'
            . '<td>' . $order['SalesChannel'] . '</td>'
            . '<td>' . $buyerEmail . '</td>';
        }
        echo '</table>';
    } else {
        echo 'No orders found.';
    }

echo "<h3>Some headers </h3>";
echo "<pre>";
print_r($response->headers());
print_r("Next Token: " . ($response->json()['payload']['NextToken'] ?? "null") . "<br>");
echo "</pre>";

echo "<h3>And the \"\$orderData\" JSON</h3>";

        // // Output the order data for debugging
        echo "<pre>";
        print_r($orderData);
        echo "</pre>";
}
