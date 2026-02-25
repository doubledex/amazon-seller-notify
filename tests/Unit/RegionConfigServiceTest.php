<?php

use App\Services\RegionConfigService;
use Tests\TestCase;

uses(TestCase::class);

test('sp-api config falls back to legacy keys', function () {
    config()->set('services.amazon_sp_api', [
        'client_id' => 'legacy-client',
        'client_secret' => 'legacy-secret',
        'refresh_token' => 'legacy-refresh',
        'application_id' => 'legacy-app',
        'marketplace_ids' => ['A1', 'A2'],
        'endpoint' => 'EU',
        'regions' => [],
        'by_region' => [],
    ]);

    $service = new RegionConfigService();
    $config = $service->spApiConfig();

    expect($config['region'])->toBe('EU')
        ->and($config['endpoint'])->toBe('EU')
        ->and($config['client_id'])->toBe('legacy-client')
        ->and($config['marketplace_ids'])->toBe(['A1', 'A2']);
});

test('sp-api config prefers region-specific keys when present', function () {
    config()->set('services.amazon_sp_api', [
        'client_id' => 'legacy-client',
        'client_secret' => 'legacy-secret',
        'refresh_token' => 'legacy-refresh',
        'application_id' => 'legacy-app',
        'marketplace_ids' => ['LEGACY'],
        'endpoint' => 'EU',
        'regions' => ['EU', 'NA'],
        'by_region' => [
            'NA' => [
                'client_id' => 'na-client',
                'client_secret' => 'na-secret',
                'refresh_token' => 'na-refresh',
                'application_id' => 'na-app',
                'marketplace_ids' => ['ATVPDKIKX0DER'],
                'endpoint' => 'NA',
            ],
        ],
    ]);

    $service = new RegionConfigService();
    $config = $service->spApiConfig('NA');

    expect($config['region'])->toBe('NA')
        ->and($config['endpoint'])->toBe('NA')
        ->and($config['client_id'])->toBe('na-client')
        ->and($config['marketplace_ids'])->toBe(['ATVPDKIKX0DER']);
});

test('sp-api endpoint enum resolves from region code', function () {
    config()->set('services.amazon_sp_api', [
        'client_id' => 'legacy-client',
        'client_secret' => 'legacy-secret',
        'refresh_token' => 'legacy-refresh',
        'application_id' => 'legacy-app',
        'marketplace_ids' => ['LEGACY'],
        'endpoint' => 'EU',
        'regions' => ['EU', 'NA'],
        'by_region' => [
            'NA' => [
                'client_id' => 'na-client',
                'client_secret' => 'na-secret',
                'refresh_token' => 'na-refresh',
                'application_id' => 'na-app',
                'marketplace_ids' => ['ATVPDKIKX0DER'],
                'endpoint' => 'NA',
            ],
        ],
    ]);

    $service = new RegionConfigService();
    $endpoint = $service->spApiEndpointEnum('NA');

    expect($endpoint->value)->toBe('https://sellingpartnerapi-na.amazon.com');
});

test('ads config falls back to legacy keys', function () {
    config()->set('services.amazon_ads', [
        'client_id' => 'legacy-ads-client',
        'client_secret' => 'legacy-ads-secret',
        'refresh_token' => 'legacy-ads-refresh',
        'base_url' => 'https://advertising-api-eu.amazon.com',
        'default_region' => 'EU',
        'regions' => [],
        'by_region' => [],
    ]);

    $service = new RegionConfigService();
    $config = $service->adsConfig();

    expect($config['region'])->toBe('EU')
        ->and($config['client_id'])->toBe('legacy-ads-client')
        ->and($config['base_url'])->toBe('https://advertising-api-eu.amazon.com');
});

test('ads config prefers region-specific keys when present', function () {
    config()->set('services.amazon_ads', [
        'client_id' => 'legacy-ads-client',
        'client_secret' => 'legacy-ads-secret',
        'refresh_token' => 'legacy-ads-refresh',
        'base_url' => 'https://advertising-api-eu.amazon.com',
        'default_region' => 'EU',
        'regions' => ['EU', 'NA'],
        'by_region' => [
            'NA' => [
                'client_id' => 'na-ads-client',
                'client_secret' => 'na-ads-secret',
                'refresh_token' => 'na-ads-refresh',
                'base_url' => 'https://advertising-api.amazon.com',
            ],
        ],
    ]);

    $service = new RegionConfigService();
    $config = $service->adsConfig('NA');

    expect($config['region'])->toBe('NA')
        ->and($config['client_id'])->toBe('na-ads-client')
        ->and($config['base_url'])->toBe('https://advertising-api.amazon.com');
});
