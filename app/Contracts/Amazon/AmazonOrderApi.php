<?php

namespace App\Contracts\Amazon;

interface AmazonOrderApi
{
    public function getOrders(string $createdAfter, string $createdBefore, ?string $nextToken = null, ?string $region = null): array;

    public function getOrderItems(string $amazonOrderId, ?string $region = null): array;

    public function getOrderAddress(string $amazonOrderId, ?string $region = null): array;
}
