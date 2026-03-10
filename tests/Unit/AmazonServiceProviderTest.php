<?php

use App\Contracts\Amazon\AmazonOrderApi;
use App\Integrations\Amazon\SpApi\SpApiClientFactory;
use App\Services\Amazon\Orders\OfficialOrderAdapter;
use App\Services\Amazon\Support\AmazonRequestPolicy;
use Tests\TestCase;

uses(TestCase::class);

it('binds amazon order api contract to official adapter', function () {
    $instance = app(AmazonOrderApi::class);

    expect($instance)->toBeInstanceOf(OfficialOrderAdapter::class);
});

it('registers amazon request policy as singleton', function () {
    $one = app(AmazonRequestPolicy::class);
    $two = app(AmazonRequestPolicy::class);

    expect($one)->toBe($two);
});

it('supports zero-arg official sp-api client factory construction', function () {
    $factory = new SpApiClientFactory();

    expect($factory)->toBeInstanceOf(SpApiClientFactory::class);
});
