<?php

use App\Services\MarketplaceListingsSyncService;
use App\Services\ProductProjectionBootstrapService;
use App\Services\ReportJobs\MarketplaceListingsReportJobProcessor;
use App\Services\SpApiReportLifecycleService;
use Tests\TestCase;

uses(TestCase::class);

function invokePrivateMethod(object $target, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionClass($target);
    $methodReflection = $reflection->getMethod($method);
    $methodReflection->setAccessible(true);

    return $methodReflection->invokeArgs($target, $arguments);
}

it('normalizes cp1252 listing title bytes in report job processor picker', function () {
    $processor = new MarketplaceListingsReportJobProcessor(
        Mockery::mock(ProductProjectionBootstrapService::class)
    );

    $value = invokePrivateMethod($processor, 'pick', [
        ['item-name' => 'Widget ' . chr(0x96) . ' 120 pack'],
        ['item-name'],
    ]);

    expect($value)->toBe('Widget ' . "\u{2013}" . ' 120 pack')
        ->and(mb_check_encoding($value, 'UTF-8'))->toBeTrue()
        ->and(str_contains($value, chr(0x96)))->toBeFalse();
});

it('normalizes cp1252 listing title bytes in listings sync picker', function () {
    $service = new MarketplaceListingsSyncService(
        Mockery::mock(SpApiReportLifecycleService::class),
        Mockery::mock(ProductProjectionBootstrapService::class)
    );

    $value = invokePrivateMethod($service, 'pick', [
        ['item-name' => 'Widget ' . chr(0x96) . ' 120 pack'],
        ['item-name'],
    ]);

    expect($value)->toBe('Widget ' . "\u{2013}" . ' 120 pack')
        ->and(mb_check_encoding($value, 'UTF-8'))->toBeTrue()
        ->and(str_contains($value, chr(0x96)))->toBeFalse();
});
