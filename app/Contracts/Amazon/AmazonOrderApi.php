<?php

namespace App\Contracts\Amazon;

use Saloon\Http\Response;

interface AmazonOrderApi
{
    public function getOrders(string $createdAfter, string $createdBefore, ?string $nextToken = null, ?string $region = null): Response;

    public function getOrderItems(string $amazonOrderId, ?string $region = null): Response;

    public function getOrderAddress(string $amazonOrderId, ?string $region = null): Response;
}
