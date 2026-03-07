<?php

namespace App\Providers;

use App\Contracts\Amazon\AmazonOrderApi;
use App\Integrations\Amazon\SpApi\SpApiClientFactory;
use App\Services\Amazon\Orders\LegacyOrderAdapter;
use App\Services\Amazon\Support\AmazonRequestPolicy;
use App\Services\MarketplaceService;
use App\Services\RegionConfigService;
use Illuminate\Support\ServiceProvider;

class AmazonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AmazonRequestPolicy::class);

        $this->app->singleton(SpApiClientFactory::class, function ($app) {
            return new SpApiClientFactory($app->make(RegionConfigService::class));
        });

        $this->app->bind(AmazonOrderApi::class, function ($app) {
            return new LegacyOrderAdapter(
                $app->make(SpApiClientFactory::class),
                $app->make(MarketplaceService::class),
                $app->make(AmazonRequestPolicy::class)
            );
        });
    }
}
