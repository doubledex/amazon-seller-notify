<?php

use App\Contracts\Amazon\AmazonOrderApi;
use App\Integrations\Amazon\SpApi\SpApiClientFactory;
use App\Services\Amazon\Orders\LegacyOrderAdapter;
use App\Services\Amazon\Support\AmazonRequestPolicy;

it('binds amazon order api contract to legacy adapter', function () {
    $instance = app(AmazonOrderApi::class);

    expect($instance)->toBeInstanceOf(LegacyOrderAdapter::class);
});

it('registers amazon request policy as singleton', function () {
    $one = app(AmazonRequestPolicy::class);
    $two = app(AmazonRequestPolicy::class);

    expect($one)->toBe($two);
});


it('supports zero-arg sp-api client factory construction for legacy call sites', function () {
    $factory = new SpApiClientFactory();

    expect($factory)->toBeInstanceOf(SpApiClientFactory::class);
});
